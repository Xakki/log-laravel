# Naming `context` fields: how to pick a name without dropping the document

> Convention shared by two libraries: **LaraLog** (`LogManager::contextTypeCorrector()`)
> and **PHPErrorCatcher** (`PhpErrorCatcher::contextTypeCorrector()`). Both write to the
> same OpenSearch cluster, so the rule tables in them must match word for word.
> Elaborates on §4.5 and §4.7 of [LoggingRules.md](LoggingRules.md).

## 0. Why this exists

OpenSearch indices don't have `ignore_malformed` set. A field gets its type from the first
document it appears in: `"order_count": 5` arrives, the field becomes `long`. When the
next service writes `"order_count": "5"` to that same field, OpenSearch **silently drops
the entire document**, not just the one field - the log simply doesn't show up, no error,
no trace. So the field name in `context` is a type declaration, not decoration.

## 1. The rule in one paragraph

**By default any field is a string.** The key is split into segments on `_`; the
**last segment** decides the type (`retry_count` → int, `item_price` → float, `debug_flag`
→ bool); if the last segment means nothing, the **first** one is checked - but only for
boolean prefixes (`is_active`, `has_access`). Matching is always by the **whole segment**,
never by substring: `report` doesn't see `port`, `datetime` doesn't see `time`, `account`
doesn't see `count`. Nothing matched - the field stays a string (and an array/object isn't
touched at all). A value that can't be cast without loss (`retry_count = "many"`) also
falls back to a string; `null` always stays `null`.

Resolution order, first match wins:

1. **`suffix`** - escape hatch: last segment `int` / `float` / `bool`. **Highest
   priority**, beats everything else including `reserved`.
2. `reserved` - library service fields.
3. `exact` - the whole key (project override level).
4. `word` - the last segment.
5. `prefix` - the first segment, only for keys with 2+ segments.
6. nothing matched - the default string.

## 2. Dictionary of canonical words

### 2.1 Last segment → **int**

| Word       | Meaning                                | Example key        |
|------------|-----------------------------------------|--------------------|
| `int`      | escape hatch, highest priority (§2.5)   | `external_int`     |
| `id`       | identifier (see §2.6)                    | `user_id`, `id`    |
| `count`    | a counter of something                  | `retry_count`      |
| `cnt`      | same, short form                        | `row_cnt`          |
| `num`      | number / quantity                       | `page_num`         |
| `qty`      | item quantity                           | `item_qty`         |
| `total`    | total **count** (not money)             | `order_total`      |
| `sum`      | sum of **counters** (not money)         | `attempt_sum`      |
| `size`     | size (elements or bytes)                | `queue_size`       |
| `bytes`    | size in bytes                           | `payload_bytes`    |
| `len`      | length                                  | `message_len`      |
| `length`   | length, full form                       | `body_length`      |
| `limit`    | fetch limit                             | `page_limit`       |
| `offset`   | fetch offset                            | `page_offset`      |
| `page`     | page number                             | `page`             |
| `depth`    | depth (recursion, tree)                 | `trace_depth`      |
| `attempt`  | attempt number                          | `retry_attempt`    |
| `retries`  | how many times it retried               | `job_retries`      |
| `level`    | numeric level                           | `nesting_level`    |
| `port`     | network port                            | `db_port`          |
| `status`   | **numeric** status code                 | `http_status`      |
| `time`     | unix time in **whole** seconds          | `start_time`       |
| `seconds`  | duration in whole seconds               | `wait_seconds`     |
| `ttl`      | time to live, seconds                   | `cache_ttl`        |

### 2.2 Last segment → **float**

| Word       | Meaning                                | Example key        |
|------------|-----------------------------------------|--------------------|
| `float`    | escape hatch, highest priority (§2.5)   | `external_float`   |
| `money`    | money                                   | `order_money`      |
| `amount`   | money (amount due)                      | `pay_amount`       |
| `price`    | unit price                              | `item_price`       |
| `duration` | duration with sub-second precision      | `job_duration`     |
| `rate`     | rate / fraction                         | `hit_rate`         |
| `ratio`    | ratio                                   | `fill_ratio`       |
| `percent`  | percent                                 | `cpu_percent`      |
| `avg`      | average                                 | `load_avg`         |
| `score`    | score / rating                          | `bot_score`        |

### 2.3 Last segment → **bool**

| Word       | Meaning                      | Example key      |
|------------|-------------------------------|------------------|
| `bool`     | escape hatch, highest priority (§2.5) | `external_bool`  |
| `flag`     | a flag                        | `debug_flag`     |
| `enabled`  | whether it's enabled          | `cache_enabled`  |
| `success`  | whether it succeeded          | `success`        |

### 2.4 First segment → **bool**

Only applies to keys with two or more segments (a lone `is` is a word, not a prefix)
and only if the last segment resolved nothing.

| Prefix    | Example key      |
|-----------|-------------------|
| `is`      | `is_admin`        |
| `has`     | `has_access`      |
| `can`     | `can_edit`        |
| `should`  | `should_retry`    |
| `allow`   | `allow_list`      |
| `enable`  | `enable_cache`    |
| `use`     | `use_cache`       |

The strings `''`, `'0'`, `'f'`, `'false'`, `'n'`, `'no'`, `'off'` (any case) are `false`.
That's exactly why the legacy DB value `'F'` doesn't turn into `true`.

### 2.5 Escape hatch `_int` / `_float` / `_bool`

A key whose **last segment** is `int`, `float`, or `bool` **always** gets that type: it is
a separate `suffix` bucket at the very top of the chain (§1), beating `reserved`, `exact`,
`word`, and `prefix`. So `price_int` is **int** (not float via the `price` word),
`count_bool` is **bool**, `seconds_float` is **float**.

This is the only way to force a type that nothing can override from above. Use it when a
field comes from a foreign system, or when the natural name yields the wrong type and the
field cannot be renamed any other way.

There is **no `_string` escape hatch.** A key that must stay a string can only be pinned
through config (`exact` / `reserved`) - see §5.

### 2.6 `id` is an **int**

In these projects an identifier is an auto-increment integer coming out of MariaDB, and we
want range queries (`order_id > 5000`) and aggregations on it - so `id` is an int word. As
a **last segment** it covers the bare key `id` and every `*_id`: `user_id`, `order_id`,
`smq_id`.

Only the whole segment counts, so `uuid`, `android`, `valid` and the camelCase `orderId`
(lowercased to the single segment `orderid`) are **not** ids and stay strings.

**An id that is not an integer must be pinned to string explicitly** - never left to the
"uncastable falls back to string" behaviour, because that fallback holds only until some
call site passes an all-digit value. The library itself pins `request_id` (a UUID generated
by `getOrCreateRequestId()`, or the incoming `X-Request-ID`): `reserved` in LaraLog,
`exact` in PHPErrorCatcher. Do the same for any hash-, hex- or UUID-valued id of your own
(`trace_id`, `span_id`, `session_id` - see §5).

Two silent traps come with this rule - §4.9 and §4.10.

## 3. How to pick the right name

| What you log | How to name it | Why |
|---|---|---|
| **Money** | `*_money`, `*_amount`, `*_price` | float. `*_sum` and `*_total` are **int** - cents get silently lost |
| **A counter** | `*_count` (or `*_cnt`) | int; `*_num`, `*_qty` are also int |
| **Duration with fractions** | `*_duration` | float, `1.75` stays `1.75` |
| **Duration in whole seconds** | `*_seconds` | int |
| **A numeric identifier** | `*_id` (or the bare `id`) | **int**: auto-increment MariaDB ids, so `order_id > 5000` and aggregations work. Not for ids with leading zeros or past `PHP_INT_MAX` - see §4.9 / §4.10 |
| **A non-numeric identifier** (UUID, hash, hex) | `*_id` is fine, but **pin it to string in config** (§5) | a UUID isn't decimal, so it lands on the string fallback - which silently flips to int the day someone passes `"2921172"`. Pin it, don't rely on the fallback |
| **A code (not a number)** | `*_code`, `*_hash`, `*_key`, `*_name`, `*_version`, `*_ip` | string by default, no rule needed |

**Why not `*_ms`.** The words `ms`, `millisecond(s)`, `sec`, `secs` are deliberately
**not** in the dictionary: the project uses one unit of measurement - seconds. A key with
`_ms` won't match anything and becomes a string, and `"latency_ms": 143` next to
`"latency_ms": "143"` is exactly the document-drop failure this whole thing exists to
prevent. Write `*_duration` (float) or `*_seconds` (int).

**Why not `*_time` for measurements.** `time` is int (unix time). A value from
`microtime(true)` under that name gets **truncated**: `1.75` becomes `1`. A duration
measurement is called `*_duration` or `*_seconds`.

## 4. Pitfalls

1. **`*_time` truncates fractions.** `exec_time = 1.75` → `1`. Measurements: `*_duration` /
   `*_seconds`.
2. **`total` and `sum` are int.** A money field with that name loses cents, and there will
   be no error. Money is only `money` / `amount` / `price`.
3. **camelCase isn't split into segments.** `orderQty` is one segment, the `qty` rule
   won't fire. Either enable `logger.snake_case`, or add an entry to `exact` in
   **lowercase**: `'exact' => ['orderqty' => 'int']`.
4. **Matching is only by whole segment.** `recount`, `account`, `report`, `datetime` are
   strings. That's a feature: without it, `account` would become a number.
5. **`_ms` / `_sec` / `_millisecond` are strings.** See §3. The only exception is the bare
   key `millisecond`, pinned in `exact` for a single legacy call site
   (`tamaranga/bff/db/database.php:498`); `milliseconds` and `exec_millisecond` remain strings.
6. **`status` is int, but `status_code` is a string.** The last segment of the latter is
   `code`, and `code` is not a dictionary word. Same for `error_code`, `response_code`.
   Need an int - name the field `*_status`; want a string with leading zeros - `*_code`.
   The `millisecond` / `milliseconds` pair in item 5 behaves the same way.
7. **One name, two kinds of values.** `status = 500` gives int, and `status = 'ok'` won't
   cast and falls back to a string - the field drifts across types again. A key like that
   must be pinned explicitly: `'exact' => ['status' => 'string']`.
8. **Arrays and objects aren't touched without a rule** (otherwise queries against nested
   paths would break), but under a scalar rule they get encoded as a JSON string:
   `(int) [1,2] === 1` would silently lose data.
9. **An id with leading zeros loses them.** `order_id = '00123'` becomes `123`, silently and
   irreversibly. If a key really carries zero-padded ids, pin it to string in config (§5).
10. **An id longer than `PHP_INT_MAX` diverges from itself.** `'99999999999999999999'`
    can't be cast and falls back to string, while a short value on the *same key* stays
    int - exactly the mixed-type field this whole document exists to prevent. Same
    remedy: pin the key to string in config (§5). Neither trap is auto-detected on
    purpose - exceptions go through config, not heuristics.
11. **`word` outranks `prefix`, so `is_bot_id` is an int, not a bool.** A bool prefix only
    fires when the last segment resolved nothing. Want a bool - name it `is_bot`.

## 5. Project-level rules

Project rules **merge on top of** the library's; a project entry wins for the same
pattern. Buckets: `suffix` / `reserved` / `exact` / `word` / `prefix` (priority - see §1).
An entry in `suffix` / `word` / `prefix` is **one lowercase segment** (`rub`, not `_rub`),
`exact` is the whole key, lowercase. An unknown bucket or unknown type is silently
ignored: a typo in the config shouldn't break logging.

**LaraLog** - `config/logger.php`:

```php
'context_type_rules' => [
    'word'  => ['rub' => 'float'],          // order_rub, refund_rub → float
    'exact' => ['deliverytime' => 'int'],   // camelCase key deliveryTime
],
```

**PHPErrorCatcher** - config key `contextTypeRulesExtra`:

```php
'contextTypeRulesExtra' => [
    'word'  => ['rub' => PhpErrorCatcher::CONTEXT_TYPE_FLOAT],
    'exact' => ['deliverytime' => PhpErrorCatcher::CONTEXT_TYPE_INT],
],
```

### Worked example - pinning an id that is not an integer

`id` is int (§2.6), but `session_id` is a hash and `trace_id` / `span_id` are hex - a value
with a letter in it falls back to string, one that happens to be all digits becomes int.
Same key, two json types, documents silently dropped. There is no `_string` suffix to
escape with, so the only remedy is an `exact` pin - and it goes into **both** libraries:

```php
// LaraLog - config/logger.php
'context_type_rules' => [
    'exact' => [
        'session_id' => 'string',   // sha256 hash
        'trace_id'   => 'string',   // OTel hex
        'span_id'    => 'string',
        'legacy_id'  => 'string',   // zero-padded '00123'
    ],
],
```

```php
// PHPErrorCatcher - contextTypeRulesExtra
'contextTypeRulesExtra' => [
    'exact' => [
        'session_id' => PhpErrorCatcher::CONTEXT_TYPE_STRING,
        'trace_id'   => PhpErrorCatcher::CONTEXT_TYPE_STRING,
        'span_id'    => PhpErrorCatcher::CONTEXT_TYPE_STRING,
        'legacy_id'  => PhpErrorCatcher::CONTEXT_TYPE_STRING,
    ],
],
```

The library already ships one such pin itself: `request_id` (§2.6).

In both libraries the same thing can be done at runtime:
`LogManager::addContextTypeRules([...])` / `PhpErrorCatcher::addContextTypeRules([...])`.

**Add a word to both libraries at the same time.** If `rub` becomes float only in
LaraLog, then `order_rub` coming from PHP legacy will arrive as a string - and the
document gets dropped.

## 6. What not to do

- **Don't invent a second name for the same thing.** `order_sum`, `order_amount`, and
  `order_price` in one project are three fields instead of one, and three different
  dashboards.
- **Don't put the value in `message`.** `"order 12345 for 500 rub"` isn't searchable or
  aggregatable; you need `["order_id" => 12345, "order_money" => 500]`.
- **Don't name a money field `sum` or `total`.** That's int - see §4.2.
- **Don't rename a field that's already indexed** without coordinating: old documents
  keep the old type, and part of the new ones start getting dropped until the index
  rotates.
- **Don't force the type with a cast at the call site** (`(string) $count`). The name
  decides the type - otherwise two call sites will drift apart.

## 7. Where this lives in code

| What | LaraLog | PHPErrorCatcher |
|---|---|---|
| Rule table | `LogManager::$contextTypeRules` | `PhpErrorCatcher::$contextTypeRules` |
| Coercion | `LogManager::contextTypeCorrector()` | `PhpErrorCatcher::contextTypeCorrector()` |
| Project rules | `config('logger.context_type_rules')` | `contextTypeRulesExtra` config |
| Tests | `tests/Unit/LogManagerTest.php` | `tests/ContextTypeCorrectorTest.php` |

Editing the dictionary means editing **both** tables and both test suites in the same change.
