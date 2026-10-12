# Teacher Dashboard Analytics API

Read-only aggregates for the dashboard home page. Both endpoints require
`auth` + `admin` (role id 3). The scope is the whole platform: there is no
per-teacher ownership in the schema, and no user/teacher id is accepted from
the client.

## Endpoints

### `GET /api/admin/analytics/overview`

| Query      | Values                                         | Default |
|------------|------------------------------------------------|---------|
| `period`   | `today`, `week`, `month`, `year`, `custom`     | `month` |
| `from`,`to`| `YYYY-MM-DD`, required when `period=custom`, inclusive, max 1100 days | – |
| `stage_id` | existing, non-deleted stage id                 | all     |

Invalid input → `422` with `error.errors.{field}`.

### `GET /api/admin/analytics/activity`

`limit` (1–50, default 15), optional `stage_id`. Returns the latest events,
newest first: `student_registered`, `coupon_redeemed`, `quiz_submitted`,
`game_exam_completed`, `lecture_created`, `notification_sent`.
Each item: `{type, occurred_at, actor, stage_name, subject, amount}`.

## Overview response

```
data.meta
  generated_at, time_basis: "database_session_time", currency: "EGP"
  stage_id, stages[{id,name,order_number}], viewer{name}, thresholds{...}
  period{preset, granularity: hour|day|month, is_partial,
         current{from,to,full_to}, previous{from,to}}
data.sections.<name> = {status: "ok"|"error", data: {...}|null}
  students, revenue, coupons, content, assessments, attendance,
  notifications, points, grades, insights
```

A section that fails is returned as `status: "error"`, `data: null` (details
are only logged). It is never reported as zeros. Time series are dense:
every bucket of the current window is present, with explicit zeros.

## Periods and comparisons

* Windows are half-open `[from, to)` in the **database session time zone**,
  the same clock as every `DEFAULT CURRENT_TIMESTAMP` column.
* `week` starts on Monday, matching the existing attendance statistics.
* If the current window has not finished yet (`is_partial`), it ends at
  "now", and the previous window covers **the same elapsed length** from the
  start of the previous period. For example, month-to-date is compared with the
  same elapsed part of last month, capped at the start of the current window.
* `custom` compares against the window of the same length directly before it.
* Growth = `(current − previous) / previous × 100`. The client shows
  "no previous baseline" instead when `previous = 0`.

## Metric definitions

| Metric | Source and rule |
|---|---|
| Students (total / active) | `students` ⨝ `users`, both not soft-deleted. Active = `users.is_active = 1`. This is a current snapshot, not tied to the selected period. |
| New students | `students.created_at` inside the window. |
| Revenue | `SUM(lecture_ownerships.purchase_price)` where `coupon_id IS NOT NULL` and `deleted_at IS NULL`, dated by `lecture_ownerships.created_at`. This is the lecture price **recorded when the coupon was redeemed**. There is no payment, refund or currency table, so it is not verified or net revenue. Grade = the lecture's stage. |
| Coupons | Same status rules as `CouponRepository::summary()`. Redemptions = ownership rows created by a coupon. Usage rate = `SUM(times_used) / SUM(max_uses)`. Payment conversion is not available. |
| Content | Non-deleted lectures, quizzes and game exams. Lecture progress/completion is not tracked. |
| Quiz attempts | Submitted = `submitted` or `auto_submitted`, dated by `finished_at`. Graded also requires `review_status` to be `not_required` or `completed` and a non-null `percentage`. Pass rate = passed / graded attempts with a non-null `is_passed`. Attempts with pending essay reviews are reported separately. |
| Game exam attempts | `completed` or `expired`, dated by `COALESCE(finished_at, expires_at)` (stored in UTC). Same graded and pass rules as quizzes. |
| Combined score | Weighted by attempt: `Σ percentage / graded attempts` over both systems, never an average of averages. |
| Attendance | `attendance_records` in the window. Attendance rate = present / records, the same as the attendance page. Absence rate = absent / records. Late is shown separately. A missing record is **not** counted as an absence. |
| Notifications | Created and sent come from `notifications`, platform-wide only. Reach and read come from `notification_recipients`. Delivery is only reported from `notification_dispatch_logs` (`tracked: false` when the table is empty). |
| Points | `student_points_ledger`, which is UTC and unique per `(source_type, source_id)`, so retries cannot double count. Grade = the stage snapshot taken when the points were earned. |

## Grade evaluation (no combined score)

Each grade is rated separately on these dimensions, and every rating returns
the evidence it is based on:

* **academic**: needs ≥ 5 graded attempts. `needs_attention` if pass rate < 50%. `strong` if pass rate ≥ 75% and average ≥ 70%.
* **attendance**: needs ≥ 10 records. `needs_attention` if absence ≥ 20%. `strong` if attendance ≥ 85%.
* **enrollment**: compares new students with the previous period (needs a baseline ≥ 3). `growing` ≥ +20%, `declining` ≤ −30%.
* **financial**: compares revenue with the previous period (needs ≥ 3 previous redemptions). Uses the same growth thresholds.
* **assessment_activity**: `no_students`, `no_assessments`, `inactive` or `active`.

Revenue and size never affect the academic rating. Samples that are too small
return `insufficient_data`. All thresholds live in
`App\Support\AnalyticsThresholds` and are included in `meta.thresholds`.

## Known data limitations

* There are no payment or refund records, so the dashboard has no net revenue,
  refunds or verified payments.
* Some columns are written with PHP's `date()` (`notifications.sent_at`,
  `quiz_attempts.finished_at`, `lecture_ownerships.purchased_at`) and others
  with the DB clock. On this environment PHP is set to `Europe/Berlin` while
  MySQL runs in `Africa/Cairo`, so those columns can be off by an hour around
  day boundaries. Fixing this needs one shared time zone (`date.timezone` in
  `php.ini`). The analytics code does not hide it.

## Suggested indexes (not applied)

The current data volumes are small. If the tables grow, these indexes match the
dashboard's range filters:

```sql
ALTER TABLE students ADD INDEX idx_students_created (created_at);
ALTER TABLE lecture_ownerships ADD INDEX idx_ownership_created (created_at);
ALTER TABLE attendance_records ADD INDEX idx_attendance_recorded (recorded_at);
ALTER TABLE notification_recipients ADD INDEX idx_nr_created (created_at);
```

## Tests

`php tests/DashboardAnalyticsTest.php` covers period and comparison
boundaries, validation, growth and rate rules, grade evaluation, insight
thresholds, attempt-weighted averages and partial section failure. It uses a
fake repository and does not touch the database.
