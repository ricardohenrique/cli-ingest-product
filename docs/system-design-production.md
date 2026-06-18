# FeedFlattener — Production System Design

> High-level blueprint for Miro / Excalidraw. No file-level detail.
> Pillars: Scalability · Reliability · Performance · Maintainability

---

## 1. Current State (Baseline)

```
[ JSONL File ]
      │  (file path)
      ▼
[ CLI Command ]  ──────────────────────────────────────┐
      │                                                 │
      ▼                                                 │
[ Ingest Handler ]                                      │
  read → flatten → validate → write                     │
      │                                                 │
      ├──► [ PostgreSQL ]  (upsert, 500-row chunks)     │
      └──► [ CSV File ]    (optional)                   │
                                                        │
                                              [ Docker / single host ]
```

**Single process, single host, file-triggered, synchronous pipeline.**

---

## 2. Target Production Architecture

### 2.1 Topology Overview

```
                        ┌─────────────────────────────────────────────┐
                        │               INGESTION TIER                 │
                        │                                              │
  [ Feed Sources ]      │  ┌──────────┐   ┌──────────┐               │
  S3 / SFTP / HTTP ───► │  │ Feed     │   │ Feed     │  ... (N)       │
                        │  │ Worker   │   │ Worker   │               │
                        │  └────┬─────┘   └────┬─────┘               │
                        │       │              │                       │
                        └───────┼──────────────┼───────────────────────┘
                                │              │
                                ▼              ▼
                        ┌─────────────────────────────────────────────┐
                        │              MESSAGE QUEUE                   │
                        │         (RabbitMQ / SQS / Kafka)            │
                        │                                              │
                        │   Topic: feed.ingest.requested               │
                        │   Topic: feed.ingest.completed               │
                        │   Topic: feed.ingest.failed                  │
                        └─────────────────────────────────────────────┘
                                           │
                    ┌──────────────────────┼──────────────────────┐
                    │                      │                      │
                    ▼                      ▼                      ▼
           ┌──────────────┐      ┌──────────────┐      ┌──────────────┐
           │   Processor  │      │   Processor  │      │   Processor  │
           │   Worker     │      │   Worker     │      │   Worker     │
           │  (PHP CLI)   │      │  (PHP CLI)   │      │  (PHP CLI)   │
           └──────┬───────┘      └──────┬───────┘      └──────┬───────┘
                  │                     │                      │
                  └──────────────────── ┼ ─────────────────────┘
                                        │
                         ┌──────────────┼──────────────┐
                         │                             │
                         ▼                             ▼
              ┌────────────────────┐       ┌─────────────────────┐
              │    PostgreSQL      │       │   Object Storage    │
              │  Primary (RW)      │       │  (S3 / GCS)         │
              │  Replica (RO)      │       │  CSV exports        │
              └────────────────────┘       └─────────────────────┘
                         │
                         ▼
              ┌────────────────────┐
              │   Read API /       │
              │   Downstream       │
              │   Consumers        │
              └────────────────────┘
```

---

### 2.2 Component Descriptions

| Component | Role | Notes |
|---|---|---|
| **Feed Sources** | Origin of JSONL feeds (S3 bucket, SFTP server, HTTP webhook) | Decoupled from pipeline |
| **Feed Workers** | Poll / receive feed arrival events; push job onto queue | Thin; only routing logic |
| **Message Queue** | Durable, ordered job delivery; back-pressure buffer | RabbitMQ (simple) or SQS (managed) |
| **Processor Workers** | Pull jobs; run full read→flatten→validate→write pipeline | Horizontally scalable; stateless |
| **PostgreSQL Primary** | Persistent store; upsert on `(sku, variant_sku)` | Primary receives writes |
| **PostgreSQL Replica** | Read offload; analytics / downstream APIs | Async replication |
| **Object Storage** | CSV export destination; raw feed archival | Cheap, durable, decoupled |
| **Read API** | Serves flattened product data to consumers | Optional; read from replica |

---

## 3. Data Flow (Production)

```
1. Feed arrives (S3 event / webhook / schedule)
       │
2. Feed Worker enqueues job:
   { feed_id, source_url, format, writer_target, requested_at }
       │
3. Processor Worker dequeues job
       │
4. Reader streams JSONL (line by line — no full load)
       │
5. Flattener explodes each product into variant rows
       │
6. Validator rejects malformed rows (log + skip)
       │
7. Writer batches rows (500/chunk) → upsert to PostgreSQL
   OR streams rows → CSV → Object Storage
       │
8. Job completion event published:
   { feed_id, processed, written, skipped, errors, duration_ms }
       │
9. Monitoring consumes completion events → metrics / alerts
```

---

## 4. Scalability

### Horizontal Scaling
- **Processor Workers** are stateless → add containers to scale throughput.
- Queue acts as the buffer: producers and consumers scale independently.
- Feed files streamed line-by-line → memory footprint is constant regardless of file size.

### Throughput Bottlenecks (ranked)
1. PostgreSQL write throughput (upsert contention on large feeds).
2. Single-file JSONL read speed (I/O bound for very large feeds).
3. Queue consumer concurrency (tunable via prefetch count).

### Scaling Levers
| Problem | Lever |
|---|---|
| DB writes too slow | Increase chunk size; add connection pooling (PgBouncer); partition `flattened_products` by feed source |
| Large file slow | Split JSONL into shards at ingestion; process shards in parallel |
| High feed volume | Add Processor Worker replicas |
| Read query load | Promote replica; add caching layer (Redis) for hot product sets |

---

## 5. Reliability

### Failure Modes & Mitigations

| Failure | Current | Production Target |
|---|---|---|
| Transient DB error mid-batch | Aborts run; partial commit persists | Retry with exponential back-off per chunk; idempotent via upsert |
| Feed file unreachable | Throws immediately | Dead-letter queue + retry policy (3 attempts, 1/5/30 min) |
| Malformed record | Log + skip (correct) | Emit to error topic for audit |
| Worker crash mid-run | Feed partially processed, job lost | Queue visibility timeout → re-deliver; upsert ensures idempotency |
| DB primary failure | Full outage | Automatic failover to replica (Patroni / RDS Multi-AZ) |

### Idempotency
Upsert on `(sku, variant_sku)` makes re-runs safe. Re-processing a feed produces the same final state.

### Observability
```
Processor Worker
  ├── Structured logs  → Log aggregator (Loki / CloudWatch)
  ├── Metrics          → Prometheus (rows/s, error rate, lag)
  ├── Traces           → OpenTelemetry (end-to-end job span)
  └── Completion event → Queue → Alerting (PagerDuty / OpsGenie)
```

---

## 6. Performance

| Technique | Where Applied |
|---|---|
| **Streaming / lazy generators** | Reader + Handler — constant memory, O(1) per record |
| **Bulk upsert (500-row chunks)** | PostgreSQL writer — amortizes round-trip cost |
| **Connection pooling** | PgBouncer in front of PostgreSQL |
| **Async I/O for feed fetch** | Feed Worker downloads to temp storage before queuing |
| **Index on `(sku, variant_sku)`** | Upsert conflict target; also serves read queries |
| **Read replica** | Offload analytics / API reads from write path |

---

## 7. Maintainability

### Extension Points (Hexagonal Ports)

```
FeedReaderPort        ← implement to add: Parquet, XML, CSV input
RowWriterPort         ← implement to add: Elasticsearch, BigQuery, Kafka output
```

New adapters are **tagged services** — no changes to Domain or Application layers.

### Deployment
- **Containerised**: each Worker is a Docker image (`php:8.4-cli`).
- **Config via env vars**: `DATABASE_URL`, `QUEUE_DSN`, `STORAGE_DSN` — no code changes per environment.
- **CI pipeline**: lint → static analysis (PHPStan) → unit tests → integration tests → build image → push to registry.
- **Migrations**: Doctrine Migrations run as a pre-deploy step, never in the worker.

---

## 8. Main Trade-offs

| Decision | Benefit | Cost |
|---|---|---|
| **CLI + Queue over HTTP API** | Simple worker; no server to manage; natural back-pressure | Harder to observe in real-time; no synchronous response to caller |
| **Streaming generators** | Constant memory; handles arbitrarily large files | Harder to debug mid-stream; errors surface late |
| **PostgreSQL as output store** | Relational queries; upsert idempotency; transactional chunks | Single write bottleneck; schema must be managed |
| **Chunk transactions (500 rows)** | Partial progress survives a crash; reduces lock duration | Partial commits on mid-run failure (re-run is safe via upsert, but state is inconsistent until complete) |
| **Hexagonal architecture** | Domain isolated from framework; easy to swap adapters | More files/interfaces; steeper onboarding for small teams |
| **Synchronous flatten-validate-write** | Simple, linear, no state machine | Cannot parallelize flattening and writing within a single feed |
| **JSONL only (current)** | Simple line-by-line streaming | Must add a reader adapter for each new feed format |

---

## 9. Diagram Checklist for Miro / Excalidraw

Draw these swimlanes / zones:

- [ ] **Feed Sources** zone (S3, SFTP, Webhook)
- [ ] **Ingestion Tier** (Feed Workers)
- [ ] **Queue** (with three topics: requested / completed / failed)
- [ ] **Processing Tier** (N × Processor Workers, horizontal)
- [ ] **Storage** (PostgreSQL Primary + Replica, Object Storage)
- [ ] **Observability** (Logs, Metrics, Traces, Alerts)
- [ ] **Consumers** (Read API / downstream)
- [ ] Arrows for: feed arrival → job enqueue → job consume → write → event emit
- [ ] Failure path: dead-letter queue → retry / alert
