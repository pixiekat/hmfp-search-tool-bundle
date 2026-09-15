# HMFP Search Tool

## Syncing physician claims

`physician_claims.active_claim_for` mirrors `status`: it holds the physician's
id while a claim is live (`verified` or `approved`) and is `NULL` otherwise. A
UNIQUE index on it is what stops two accounts holding the same physician.

The app keeps the two in step (`PhysicianClaim::syncExclusivity()`), so editing
`status` by hand in SQL leaves them out of step. That can go wrong in two
directions, and each has its own find-and-repair pair below.

**Always run the `SELECT` first**, so you know which rows the `UPDATE` will
touch before it touches them.

### 1. Live claims that lost their slot

Caused by setting `status` back to `verified` or `approved` by hand (for
example, un-revoking a claim).

**Symptom:** the app still treats the claim as live — the voter counts by
`status` — but the UNIQUE index is no longer protecting that physician, so a
second account could verify a claim on the same person.

```sql
-- Find: live claims with no exclusivity slot.
SELECT id, physician_id, status, active_claim_for
  FROM physician_claims
 WHERE status IN ('verified', 'approved')
   AND active_claim_for IS NULL;

-- Repair: give each one its slot back, exactly as syncExclusivity() would.
UPDATE physician_claims
   SET active_claim_for = physician_id
 WHERE status IN ('verified', 'approved')
   AND active_claim_for IS NULL;
```

> **This UPDATE can fail on purpose.** If two live claims point at the same
> physician, MariaDB stops it with a duplicate-key error on
> `active_claim_for`. That is the index doing its job, not a broken query: two
> accounts are claiming the same person. A data steward must revoke one of
> them before the repair will run. Do not drop the index to get past it.

### 2. Dead claims still holding a slot

Caused by setting `status` to `pending`, `rejected` or `revoked` by hand (for
example, revoking a claim in SQL instead of from the claims admin screen).

**Symptom:** the UNIQUE index refuses every future claim on that physician,
and the claimant sees "Someone else has just claimed this profile" even though
nobody has.

```sql
-- Find: non-live claims that still hold the exclusivity slot.
SELECT id, physician_id, status, active_claim_for
  FROM physician_claims
 WHERE status NOT IN ('verified', 'approved')
   AND active_claim_for IS NOT NULL;

-- Repair: release the slot.
UPDATE physician_claims
   SET active_claim_for = NULL
 WHERE status NOT IN ('verified', 'approved')
   AND active_claim_for IS NOT NULL;
```

If the list of live statuses ever changes, update these queries to match
`ClaimStatus::grantsEditing()`.

## Importer

`hmfp:import:physicians` (`src/Command/ImportPhysiciansCommand.php`) reads the
hospital's provider demographics extract and turns it into physicians,
departments, facilities, specialties and languages, plus the links between
them. The extract's format belongs to the hospital's export team; the importer
reads it as it is and reports anything it cannot use.

### Running it

```sh
# Always dry-run first: parses and reports, writes nothing.
php bin/console hmfp:import:physicians --dry-run

# The real import. --no-debug stops the profiler keeping every INSERT in memory.
php bin/console hmfp:import:physicians --no-debug

# A different file, comma-delimited, first 500 providers only.
php bin/console hmfp:import:physicians /path/to/extract.csv -d ',' --limit=500
```

With no file argument it imports the sample in `docs/sample-provider-demographics.csv`.

| Option               | What it does                                                         |
|----------------------|----------------------------------------------------------------------|
| `-d`, `--delimiter`  | Field delimiter. Defaults to `\|`: the extract is pipe-delimited despite the `.csv` name. |
| `-b`, `--batch-size` | Physicians written per flush (default 500).                          |
| `-l`, `--limit`      | Stop after this many **providers** (not rows). Turns off departure detection. |
| `--skip-departments` | Leave departments and their links untouched.                         |
| `--skip-facilities`  | Leave facilities and their links untouched.                          |
| `--dry-run`          | Do everything except write.                                          |
| `-v`                 | Also print the line number of every skipped row or value.            |

### The shape of the extract

- **One row per provider per department.** The sample has 21,556 rows but only
  about 10,933 people. Repeat rows aren't errors; they're where a physician's
  extra departments and facilities come from.
- **Three different separators in one file:**
  - `|` between fields
  - `;` inside `department` and `specialty` (`Ophthalmology; Surgery` is two)
  - `,` inside `languages` (`Albanian, Italian, Spanish`)

  The comma never splits any other column, because facility names contain real
  commas (`Mount Auburn Cambridge, IPA`).
- **Columns are found by header name, not position.** If upstream reorders the
  spreadsheet, nothing breaks. If a column is renamed or missing, the import
  stops before writing anything and lists what it found. Every column looking
  missing usually means the wrong `--delimiter`.
- **Export artifacts are cleaned:** trailing commas on `facility_name` (0 to 7 of
  them), a UTF-8 BOM on the first header cell, and doubled spaces in names.
- **Required per row:** `cred_id`, `last_name`, `degree`. Rows missing one are
  skipped and counted by reason. An empty `department` is fine; about 4% of
  providers have none.

### How a run works

The file is read **twice**, on purpose.

1. **Pass 1: scan.** Reads every row without writing. It counts rows, collects
   every distinct department, facility, specialty and language name, and builds
   each provider's full list of them (keyed by `cred_id`), merging their repeat
   rows.
2. **Sync the lookup tables.** Creates any department, facility, or specialty /
   language term that doesn't exist yet, in one go, then reads back their ids.
   Names are matched case-insensitively, so `Cardiology` and `cardiology` are
   one department.
3. **Pass 2: import physicians.** Streams the file again, one provider at a time
   (repeat rows are skipped, since pass 1 already has their data), and decides
   insert, update or nothing. Writes happen in batches of `--batch-size`.
4. **Stamp and report.** Every physician seen gets the same `lastSeenInImportAt`
   timestamp, then a summary table is printed and the run is written to the
   audit log.

Doing all the department and facility work up front means pass 2 only ever
needs integer ids. Those still work after Doctrine's `clear()` detaches every
entity between batches; a cached `Department` object would not.

### Matching: "have I seen this person before?"

Identity is the **`cred_id`** column, a credentialing UUID that `physicians`
stores under a UNIQUE index.

| In the extract                          | What happens                              |
|-----------------------------------------|-------------------------------------------|
| `cred_id` already in `physicians`       | Update in place, or leave alone if nothing changed |
| `cred_id` not found, but an old row with **no** `cred_id` has the same name + degree | **Adopt** that row: it gets the `cred_id` written in |
| Neither                                 | Insert a new physician                    |

Adoption exists for physicians created before the `cred_id` column did. Each
such row can be adopted once, and rows that already have a `cred_id` are never
matched by name. That's what keeps two providers who share a name (the sample
has several) from merging into one record.

Name-plus-degree alone is not used as identity because it fails both ways: a
physician who earns an MPH would become two rows, and two different Anne M.
Valente MDs would become one.

### What an import never does

- **Never deletes a physician.** Someone missing from the extract is reported
  under **Departures** (a stale `lastSeenInImportAt`) and left in place. One
  truncated export would otherwise wipe thousands of rows and their bios. A
  `NULL` stamp means "created by hand", not "departed". See
  `PhysicianRepository::countDepartedSince()`.
- **Never touches fields the extract doesn't own**, such as `bio`. Only
  `legalName`, `credentials`, `npi`, `gender`, `phone`, `preferredFullName` and
  the links are overwritten.
- **Never mixes vocabularies.** Specialty and language links share
  `physician_terms`, so each is diffed only against links in its own vocabulary.
  Otherwise the specialty sync would delete every language link.
- **Never guesses a department's `md_staff_code`.** New departments get `NULL`;
  fill them in through the admin UI.

### Reading the summary

- **Created / Updated / Unchanged:** a normal re-run of a similar file is
  mostly *unchanged*.
- **Adopted:** pre-`cred_id` rows given an identity. Should drop to 0 after the
  first run.
- **Duplicate rows (same cred_id):** the repeat rows described above. Expected
  and large.
- **Rows skipped / Why rows or values were skipped:** grouped by reason. Re-run
  with `-v` for line numbers to send back to the export team.
- **Departures:** only shown for a full, live run.

> **Warning sign:** a run with a sudden large *created* count **and** a matching
> *departures* count, on a file that should have been mostly unchanged, means
> `cred_id`s were reissued upstream. Every physician was re-inserted and the
> originals look departed. Suspect the identifier before the importer, and
> raise it with the export team.

### Adding a column from the extract

Only some of the extract's 20 columns are imported (for example `suffix`,
`image_url` and `online_booking_url` are not). To add one, it has to be wired
through in several places:

1. **`COLUMNS`:** map an internal key to the literal header name.
2. **Pass 2 (`importPhysicians()`):** read it from `$record`, usually through
   `nullIfBlank()`, and call its setter in **both** the insert branch and the
   update branch.
3. **`loadImportIndex()`:** add it to the `select()` and to the `$byCredId`
   entry, so the current value is in memory.
4. **The change check (`$fieldsChanged`):** compare the new value against the
   indexed one. Skipping this means a change to only that column is reported as
   *unchanged* and never written.

The same field ends up with three spellings along the way, which is easy to trip
over:

```text
┌──────────────────────┬─────────────────────┬──────────────────────────────────────────┐
│        Where         │        Name         │        Why it's spelled that way         │
├──────────────────────┼─────────────────────┼──────────────────────────────────────────┤
│ CSV header / $record │ preferred_full_name │ From the extract, via COLUMNS            │
├──────────────────────┼─────────────────────┼──────────────────────────────────────────┤
│ SQL alias / $row     │ preferredFullName   │ Whatever you write after AS in the       │
│                      │                     │ select() in loadImportIndex()            │
├──────────────────────┼─────────────────────┼──────────────────────────────────────────┤
│ Index / $match       │ preferredFullName   │ Whatever key you choose in the $byCredId │
│                      │                     │ entry in loadImportIndex()               │
└──────────────────────┴─────────────────────┴──────────────────────────────────────────┘
```

If the key is spelled differently in steps 3 and 4, a debug run stops with
`Undefined array key`. Under `--no-debug` it's only a warning: `$match[...]`
reads `null`, the comparison fails on every run, and every physician is
reported as *updated*. So test a new column with a normal dry run first.
