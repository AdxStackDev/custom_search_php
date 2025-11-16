# `api.php` – Custom Search API Proxy

This PHP file acts as a web API proxy for Custom Search. It enables users to perform searches, filter results, and export them in CSV or JSON formats. The API supports both GET and POST methods, allowing for flexible integration with frontend apps or third-party tools.

---

## Main Features

- Accepts search queries and advanced filters via POST or GET.
- Proxies requests to Custom Search API.
- Batches multiple keywords in a single search.
- Supports exporting search results as CSV or JSON.
- Maintains session for exporting previous results.
- Provides thorough input validation and utility helpers.

---

## Core Functionalities

### Utility Functions

These functions sanitize input, build queries, and manage exports.

- **safe($x):** Escapes output for HTML safety.
- **clamp_int($v, $min, $max, $fb):** Strict integer bounds for parameters.
- **valid_date($s):** Ensures dates are valid and in `Y-m-d` format.
- **list_tokens($s):** Splits multi-value fields (by linebreaks or commas).
- **build_query_text($f, $base):** Constructs advanced search queries from input.
- **build_cse_params($f, $q, $start, $num):** Assembles parameters for the Custom Search API.
- **http_get_json($p):** Makes HTTP requests to and parses JSON responses.
- **send_download_headers($filename, $type):** Sets headers for downloadable files.
- **export_csv($all):** Streams CSV download of results.
- **export_json($all):** Streams JSON download of results.

---

## Request Handling and Workflow

The script’s logic flows as follows:

1. **Parameter Gathering:** Collects fields from GET or POST, mimicking a search form.
2. **Export Handling:** If `GET?export=csv|json`, exports previously stored results from the session.
3. **Search Requests:** On `POST`, invokes Custom Search with parameters, returns JSON, and stores results in the session.
4. **Default Info:** On any other request, gives a help/info JSON response.

---

### Search Request Lifecycle

```mermaid
flowchart TD
    A[Client Sends POST Request] --> B[Validate api_key and cx]
    B -- Missing --> C[Return 400 Error]
    B -- Present --> D[Prepare Search Parameters]
    D --> E[For Each Keyword]
    E --> F[Build Query and API Params]
    F --> G[Call Custom Search API]
    G --> H[Parse and Store Results]
    H --> I[Sleep 120ms (Throttle)]
    I --> E
    E -- All Keywords Processed --> J[Store Results in Session]
    J --> K[Return JSON Results to Client]
```

---

## API Endpoints and Interactive Documentation

Below are the documented endpoints provided by this file, including request/response examples:

---

```api
{
    "title": "Perform Search",
    "description": "Executes a search against Custom Search API with advanced filters and returns results in JSON format.",
    "method": "POST",
    "baseUrl": "https://yourserver/api.php",
    "endpoint": "/api.php",
    "headers": [
        {
            "key": "Content-Type",
            "value": "application/x-www-form-urlencoded",
            "required": true
        }
    ],
    "queryParams": [],
    "pathParams": [],
    "bodyType": "form",
    "formData": [
        {"key": "api_key", "value": "Your API Key", "required": true},
        {"key": "cx", "value": "Custom Search Engine ID", "required": true},
        {"key": "keywords", "value": "Search keywords (newline or comma separated)", "required": false},
        {"key": "intitle", "value": "Words required in title", "required": false},
        {"key": "inurl", "value": "Words required in URL", "required": false},
        {"key": "intext", "value": "Words required in text", "required": false},
        {"key": "filetype", "value": "File type filter", "required": false},
        {"key": "exact", "value": "Exact phrase required", "required": false},
        {"key": "exclude", "value": "Words to exclude", "required": false},
        {"key": "orTerms", "value": "Alternative words (OR)", "required": false},
        {"key": "hq", "value": "High quality required words", "required": false},
        {"key": "site", "value": "Sites to include (newline or comma separated)", "required": false},
        {"key": "site_exclude", "value": "Sites to exclude", "required": false},
        {"key": "after", "value": "After date (YYYY-MM-DD)", "required": false},
        {"key": "before", "value": "Before date (YYYY-MM-DD)", "required": false},
        {"key": "aroundA", "value": "Word A for proximity search", "required": false},
        {"key": "aroundB", "value": "Word B for proximity search", "required": false},
        {"key": "aroundN", "value": "Proximity distance (default: 3)", "required": false},
        {"key": "safe", "value": "Safe search (off|medium|high)", "required": false},
        {"key": "gl", "value": "Country restrict", "required": false},
        {"key": "lr", "value": "Language restrict", "required": false},
        {"key": "hl", "value": "Interface language", "required": false},
        {"key": "searchType", "value": "Type (image|)", "required": false},
        {"key": "num", "value": "Results per page (1-10)", "required": false},
        {"key": "page", "value": "Page number (1-10)", "required": false}
    ],
    "requestBody": "api_key=YOUR_API_KEY&cx=YOUR_CX&keywords=example",
    "responses": {
        "200": {
            "description": "Search results found",
            "body": "{\n  \"total\": 5,\n  \"results\": {\n    \"example\": [\n      {\"title\": \"Example Title\",\"link\": \"https://example.com\",\"displayLink\": \"example.com\",\"snippet\":\"...\",\"mime\":\"text/html\"},\n      ...\n    ]\n  }\n}"
        },
        "400": {
            "description": "Missing API key or CSE ID",
            "body": "{\n  \"error\": \"Missing api_key or cx (CSE ID).\"\n}"
        }
    }
}
```

---

```api
{
    "title": "Export Results as CSV",
    "description": "Exports the latest search results stored in session as a downloadable CSV file.",
    "method": "GET",
    "baseUrl": "https://yourserver/api.php",
    "endpoint": "/api.php?export=csv",
    "headers": [],
    "queryParams": [],
    "pathParams": [],
    "bodyType": "none",
    "requestBody": "",
    "responses": {
        "200": {
            "description": "CSV file streamed",
            "body": "query,title,link,displayLink,snippet,mime\nexample,Example Title,https://example.com,example.com,...,text/html\n..."
        },
        "404": {
            "description": "No results in session",
            "body": "{\n  \"error\": \"No results to export.\"\n}"
        }
    }
}
```

---

```api
{
    "title": "Export Results as JSON",
    "description": "Exports the latest search results stored in session as pretty-printed JSON.",
    "method": "GET",
    "baseUrl": "https://yourserver/api.php",
    "endpoint": "/api.php?export=json",
    "headers": [],
    "queryParams": [],
    "pathParams": [],
    "bodyType": "none",
    "requestBody": "",
    "responses": {
        "200": {
            "description": "JSON file streamed",
            "body": "{\n  \"example\": [\n    {\"title\": \"Example Title\", \"link\": \"https://example.com\", ...}\n  ]\n}"
        },
        "404": {
            "description": "No results in session",
            "body": "{\n  \"error\": \"No results to export.\"\n}"
        }
    }
}
```

---

```api
{
    "title": "API Info (GET)",
    "description": "Returns instructions for using the API if no POST or export is requested.",
    "method": "GET",
    "baseUrl": "https://yourserver/api.php",
    "endpoint": "/api.php",
    "headers": [],
    "queryParams": [],
    "pathParams": [],
    "bodyType": "none",
    "requestBody": "",
    "responses": {
        "200": {
            "description": "API Info",
            "body": "{\n  \"info\": \"api.php expects POST for search and GET?export=csv|json for exports.\"\n}"
        }
    }
}
```

---

## Supported Fields

| Parameter      | Description                             | Example        | Required |
|----------------|-----------------------------------------|----------------|----------|
| api_key        | API key                          | (see)   | Yes      |
| cx             | Custom Search Engine ID                 | (see)   | Yes      |
| keywords       | Search keywords, comma or newline split | `apple,orange` | No       |
| intitle        | Words required in page title            | science        | No       |
| inurl          | Words required in URL                   | blog           | No       |
| intext         | Words required in text                  | "climate"      | No       |
| filetype       | File type filter                        | pdf            | No       |
| exact          | Exact phrase required                   | "solar power"  | No       |
| exclude        | Exclude words                           | politics       | No       |
| orTerms        | Alternative (OR) words                  | apple,banana   | No       |
| site           | Only these sites                        | example.com    | No       |
| site_exclude   | Exclude these sites                     | spam.com       | No       |
| after/before   | Date range (YYYY-MM-DD)                 | 2023-01-01     | No       |
| aroundA/B/N    | Proximity search                        | alpha, beta, 5 | No       |
| safe           | Safe search mode                        | off/medium/high| No       |
| gl             | Country restrict                        | US, DE         | No       |
| lr             | Language restrict                       | lang_en        | No       |
| hl             | UI language                             | en             | No       |
| searchType     | image (for image search)                | image          | No       |
| num            | Results per page (1-10)                 | 5              | No       |
| page           | Page number (1-10)                      | 2              | No       |

---

## Error Handling

- Returns `400` for missing required fields (`api_key`, `cx`).
- Returns JSON error message for failed API calls.
- Returns error if exporting but no session results found.

---

## Security & Best Practices

- **Session usage:** Stores search results for exporting.
- **Input sanitation:** Uses `htmlspecialchars` and strict checks.
- **API throttling:** Sleeps briefly between requests to avoid rate limits.
- **SSL context:** Disables SSL verification for API calls (not for production).

```card
{
    "title": "Production Security Note",
    "content": "Enable SSL verification in http_get_json() for production environments to ensure secure API calls."
}
```

---

## Example: Search and Export Workflow

1. **POST** search parameters to `/api.php` → Get JSON results.
2. Results stored in session.
3. **GET** `/api.php?export=csv` or `/api.php?export=json` → Download last results.

---

## Conclusion

This script is a robust, extensible backend service for federated search with advanced query options and batch processing. It is ideal for integrating advanced search into web apps, dashboards, or internal tools.

---
