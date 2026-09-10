# Spark API v2 wrapper

`spark.php` is a single-file, dependency-free PHP wrapper around the [Spark](https://spark.re) API v2 — the CRM/inventory platform behind our real-estate project sites. It is the canonical copy: start every new Spark integration by copying this file, and push improvements back here instead of letting per-project copies drift.

It covers the two things these sites actually need:

- **Lead capture** — post a contact (a registrant from a website form) into the Spark project.
- **Inventory** — pull units with their floor plans, statuses and additional fields, for availability tables and unit detail pages.

Everything is `static`, so there is nothing to instantiate and no bootstrapping.

## Requirements

PHP 7.1+ with the cURL extension. No Composer package, no autoloader.

## Install

Copy `spark.php` into the project — in our WordPress themes that is `wp-content/themes/<theme>/inits/spark.php` — and require it:

```php
require_once get_template_directory() . '/inits/spark.php';
```

Then set the key at the top of the file:

```php
private static $api_key = "YOUR_SPARK_API_KEY";
```

**The key must be a project-level API key, not a company-level one.** Creating and updating contacts is rejected with a company key. Ask the client's Spark admin for a key scoped to the specific project.

## Posting a contact

`postContact()` is the whole lead-capture path. Build the payload, send it, branch on the result:

```php
$result = SparkAPI::postContact([
    'email'      => $email,
    'first_name' => $first_name,
    'last_name'  => $last_name,
    'phone'      => $phone,
    'registration_source_id' => 1299,
    'question_answers' => [
        ['question_id' => 123, 'answers' => ['Yes']],
    ],
]);

if ($result['status'] === 'success') {
    // redirect to thank-you page
} else {
    error_log('Spark: ' . $result['message'] . ' (HTTP ' . $result['code'] . ')');
}
```

On success you get `['status' => 'success', 'message' => '', 'data' => <created contact>]`.

On failure you get `status`, `message` (Spark's `error_message`), `code` (the HTTP status), `curl_error` (empty unless the request never reached Spark) and `data` (the raw decoded body). Log all of them — `message` alone is empty on transport errors and on non-JSON responses.

**HTTP 200 and 201 are both success.** 201 means the contact was created; 200 means Spark matched the email to an existing contact and updated it. Treating only 201 as success reports real submissions as failures.

### Legacy v1 payloads

`postContact()` runs `sanitizeV1Fields()` on the data first, so a form still written against API v1 keeps working. It:

- drops empty fields, which v2 rejects where v1 ignored them;
- converts `answers` into v2 `question_answers`;
- converts `standardized_fields_attributes` into v2 `additional_fields`;
- resolves `brokerage_name` into a `brokerage_id`, creating the brokerage if it does not exist yet.

New forms should send the v2 shape directly.

## Reading inventory

```php
$units = SparkAPI::getUnitsWithDetails();
```

That is `getUnits()` plus the three `populateUnits*()` calls. Units come back keyed by unit id, each with a `floorplan` and `status` object merged in, and every additional field flattened onto the unit under a snake_cased version of its name (`Parking Included` becomes `$unit['parking_included']`).

Call the pieces separately when you do not need all of it — each `populateUnits*()` call is at least one extra round trip:

```php
$units = SparkAPI::getUnits();
SparkAPI::populateUnitsStatuses($units);   // takes $units by reference
```

There is no caching in this class. On a WordPress site, wrap the call in a transient or a scheduled sync — do not hit the API on every page load.

## Method reference

| Method | Purpose |
| --- | --- |
| `postContact(array $data)` | Create or update a contact. Returns `['status' => 'success'\|'failed', ...]`. |
| `getUnits()` | All inventory units, keyed by id. |
| `getUnitsWithDetails()` | `getUnits()` + floor plans + statuses + additional fields. |
| `populateUnitsFloorplans(array &$units)` | Merges a `floorplan` object into each unit. |
| `populateUnitsStatuses(array &$units)` | Merges a `status` object into each unit. |
| `populateUnitsAdditionalFields(array &$units)` | Flattens additional fields onto each unit. |
| `getBrokerage(string $name)` | Finds a brokerage by exact name, creating it if missing. Throws on failure. |
| `getCountries()` | The country list, for form dropdowns. |

The private `get()` / `post()` helpers handle auth and JSON. `get()` requests `per_page=100`, and the paginated methods loop until a page comes back empty.

## Gotchas

**Registration source ids are project-scoped.** `GET /v2/registration-sources` returns *company-level* names, but a project can rename the same id. On one project id `1052` is company-wide "Website" while the project displays it as "Agent Web Registrant". Sending an id picked from the company list can file the lead under a visibly wrong source. Verify the project-scoped name first — it appears in the `registration_sources` array on `GET /v2/contacts/:id`, which carries both the company entry and the `project_id`-scoped one.

**Custom fields do not need the custom-fields permission.** `custom_field_values` and `question_answers` are accepted on contact create even when the key lacks the dedicated "Custom Fields" permission.

**The base URL is hardcoded** to `https://api.spark.re/v2/`. Spark's demo environment prefixes the host (`https://demo-api.spark.re/v2/`); point the class at it by editing both `get()` and `post()`.

**No retry, no rate limiting.** A failed call fails once. If a form submission matters, log the failure payload so the lead can be recovered manually.
