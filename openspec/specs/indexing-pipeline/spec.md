# indexing-pipeline Specification

## Purpose

Defines how published posts get embedded into the site's dedicated Qdrant Cloud collection: batches of 100 posts embedded with server-side inference in single requests, a resumable background indexing job that runs without an open browser, progress reporting, and retry behavior on transient Qdrant failures.

## Requirements

### Requirement: Batch-of-100 embedding with single-request inference
Bulk indexing SHALL process published posts in ascending post-ID order in batches of at most 100 posts. For each batch it SHALL issue at most one request retrieving the stored payloads of the batch's post IDs and at most one upsert request, and the upsert SHALL carry every point of the batch that needs embedding, each with its text and model as the server-side inference object, so Qdrant Cloud embeds the whole batch in that single request.

#### Scenario: 100 stale posts in one batch
- **WHEN** 100 published posts need embedding and indexing runs
- **THEN** exactly one upsert request is issued for the batch, containing 100 points, each carrying only its text and model for server-side inference

#### Scenario: current posts are not re-sent
- **WHEN** a batch's posts are all already current in the collection
- **THEN** no upsert request is issued for that batch and the posts are reported as skipped

#### Scenario: final partial batch
- **WHEN** fewer than 100 posts remain after the current batch's cursor
- **THEN** that final batch is processed and the run completes normally

### Requirement: Fingerprint skip check at batch granularity
For every batch, the indexer SHALL read the actual stored payloads from the collection and compare them against locally computed content fingerprints, re-embedding only points that are missing, stale, or legacy-preserved per the existing rules. A resumed or repeated run SHALL therefore never re-embed unchanged posts, and interrupted runs, resets, and changed clusters SHALL be safe to re-run.

#### Scenario: resume after interrupted run
- **WHEN** indexing is stopped midway and indexing is started again
- **THEN** posts already embedded with matching fingerprints are skipped and only the remainder is embedded

#### Scenario: collection reset between runs
- **WHEN** the collection was reset or points were deleted after a previous run
- **THEN** the next indexing run re-embeds the missing posts

### Requirement: Indexing continues without an open browser session
Starting indexing SHALL return promptly without performing batch processing within the start request, and batch processing SHALL continue server-side without requiring the requesting browser session to remain open. Closing the settings page after starting SHALL NOT stop or lose the indexing run.

#### Scenario: user closes the page immediately after starting
- **WHEN** indexing is started and the settings page is closed before any batch completes
- **THEN** the run continues in the background and eventually completes all batches

#### Scenario: prompt start response
- **WHEN** indexing is started
- **THEN** the start response arrives with the job's initial state and does not wait for any Qdrant embedding work

### Requirement: Background job state is persisted server-side
The indexing job SHALL persist its state server-side: status (running, done, or stopped), the post-ID cursor, running totals of indexed, skipped, and failed posts, and its recent error details. The persisted state SHALL survive page reloads and browser closes so that progress is never lost or reset by the client.

#### Scenario: state survives close and reopen
- **WHEN** the settings page is closed during a run and the job state is read later
- **THEN** the reported cursor and totals reflect every batch completed so far, not just the browser session

### Requirement: Indexing job lifecycle rules
Starting indexing while a job is already running SHALL NOT start a second concurrent job. A stop request SHALL prevent further batches from starting while keeping completed work. A failed batch SHALL record its error and the job SHALL continue with the next batch, and starting indexing again after completion or stop SHALL begin a fresh pass that skips unchanged posts.

#### Scenario: start while running
- **WHEN** indexing is started while a background job is already running
- **THEN** no second job starts and the current job's state is returned

#### Scenario: stop keeps completed work
- **WHEN** a stop is requested after some batches completed
- **THEN** no further batches run, completed embeddings remain in the collection, and progress reports show the job as stopped

#### Scenario: one bad batch does not stall the run
- **WHEN** a batch's upsert fails after retries
- **THEN** that batch's failure is recorded with its cause, and subsequent batches still run to completion

### Requirement: Progress reporting and honest batch failures
A status endpoint SHALL expose the persisted job state for users who can manage options: status, cursor, indexed/skipped/failed totals, and recent error details. The admin settings page SHALL poll this endpoint while a job is running, render the progress live, and show the same live progress when reopened during a run. A batch failure SHALL be reported with its cause and the post IDs it covers.

#### Scenario: reopen the settings page mid-run
- **WHEN** the settings page is loaded while the background job is running
- **THEN** the page shows the job's live progress without restarting or duplicating the job

#### Scenario: batch failure surfaces its scope
- **WHEN** a batch fails after retries
- **THEN** the failure is reported with the batch's post-ID range and the remote error message, and the failed count includes those posts

### Requirement: Transient-failure retry on batch requests
Every Qdrant request of the batch pipeline SHALL pass through the existing transient-failure retry behavior: up to three attempts on the recorded transient cURL and HTTP classes with backoff, and the TLS-mismatch guidance message when such errors persist. Batch operations SHALL remain idempotent so a retry can never duplicate or corrupt data.

#### Scenario: transient glitch during batch upsert
- **WHEN** a batch upsert hits a transient TLS or 502/503/504 error and succeeds on retry
- **THEN** the batch is reported as indexed, not as failed

#### Scenario: persistent TLS mismatch
- **WHEN** batch requests keep failing with TLS certificate mismatch through all attempts
- **THEN** the failure is reported with the clear retry-later guidance message instead of the raw cURL error

### Requirement: Single-post save synchronization unchanged
Saving a published post SHALL keep scheduling a per-post background synchronization that embeds, skips, or removes that post alone, and deleting a post SHALL remove its point. The bulk indexing job SHALL NOT interfere with these per-post updates.

#### Scenario: editing one post after bulk indexing
- **WHEN** a single published post is edited and saved
- **THEN** that post alone is re-embedded through the per-post path and other posts are untouched

#### Scenario: post deleted while indexing runs
- **WHEN** a post is deleted while a bulk indexing job is running
- **THEN** its point is removed from the collection and the deletion is not resurrected by the running job's batches
