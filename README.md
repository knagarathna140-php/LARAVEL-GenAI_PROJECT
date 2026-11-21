# Stone ERP AI Assistant

An AI assistant for a stone and quartz ERP. Staff ask questions in plain English ("How many 3cm Calacatta slabs are in Houston?") and get answers from live ERP data. Built with Laravel 12, PHP 8.3, PostgreSQL, Redis and Docker. The default AI is Claude on Amazon Bedrock, and the provider can be swapped.

Everything is sample data.

## Run it (one command)

```
docker compose up --build
```

The first start installs dependencies, creates the schema and loads sample data. The API is then at `http://localhost:8000`.

## Run locally on Windows without Docker

Requires PHP 8.3+ and Composer. This project also runs on PHP 8.2 for local API testing.

```powershell
composer install
Copy-Item .env.example .env
New-Item -ItemType File -Force database/database.sqlite
```

Set these values in `.env`:

```dotenv
DB_CONNECTION=sqlite
DB_DATABASE=database/database.sqlite
CACHE_STORE=array
SESSION_DRIVER=array
```

Then initialize and start the app:

```powershell
php artisan key:generate
php artisan migrate:fresh --seed
php artisan serve --host=127.0.0.1 --port=8000
```

Open `http://127.0.0.1:8000/up`. Login and API requests use `http://127.0.0.1:8000/api`.

To use the AI, copy `.env.example` to `.env` **before** starting and fill in one of these:

- Bedrock (default): `AWS_BEARER_TOKEN_BEDROCK` (a Bedrock API key), `AWS_DEFAULT_REGION`, and `BEDROCK_MODEL_ID`. The model must be enabled in your AWS account.
- OpenAI: set `AI_DRIVER=openai` and `OPENAI_API_KEY`.

Sample users (password is `password`):

| Email | Role |
|---|---|
| sales@example.com | Sales |
| purchasing@example.com | Purchasing |

## Try it

```
curl -X POST localhost:8000/api/login -H "Accept: application/json" -d "email=sales@example.com" -d "password=password"
# -> {"token":"1|abc...","role":"sales"}

curl -X POST localhost:8000/api/assistant -H "Accept: application/json" -H "Authorization: Bearer <token>" \
     -d "message=How many 3cm Calacatta slabs do we have in Houston?"
# -> {"reply":"...42 slabs...","conversation_id":"<uuid>"}
```

Send the same `conversation_id` back for follow-ups ("And in Dallas?") and for `confirm`.

### Endpoints

| Method | Path | Notes |
|---|---|---|
| POST | /api/login | returns a Sanctum token |
| POST | /api/assistant | `message`, optional `conversation_id` |
| GET | /api/products, /api/stock | all roles |
| GET | /api/purchase-orders, /api/purchase-orders/{po} | Costs and freight only for Purchasing |
| POST | /api/purchase-orders | Purchasing only (Form Request) |
| GET | /api/suppliers | bank details only for Purchasing |

## How the assistant works

1. `AssistantController` validates the request (Form Request) and calls `AssistantService`.
2. `AssistantService` sends the question, the conversation and the tool list to the AI (`AiProvider`).
3. The AI can only call four PHP tools in `ToolRegistry`: `checkStock`, `searchPurchaseOrders`, `getLandedCost`, `draftPurchaseOrder`. There is **no SQL from the AI**. Tools call the same service classes the REST API uses.
4. Each tool returns JSON and the AI writes the answer from it. Up to 5 tool rounds per question.

**Roles are enforced in code, inside the tools and services.** `getLandedCost` and `draftPurchaseOrder` refuse non-Purchasing users, and `PurchaseOrderService::present()` leaves out unit cost and freight for Sales. Supplier bank details are encrypted in the database and are never returned by any tool.

**Confirm before saving.** `draftPurchaseOrder` prices the draft and holds it in Redis for 30 minutes. Nothing goes to the database. When the user's message is exactly "confirm", code (not the AI) saves it as a `draft` PO, and it checks the role again. "cancel" discards it. The AI cannot save a PO by itself.

**Memory.** The last 20 messages (question and answer text) are kept in Redis per user and conversation for 2 hours.

**Swappable provider.** `AiProvider` is a one-method interface. `BedrockProvider` (Converse API) and `OpenAiProvider` are included, picked by `AI_DRIVER` in `AppServiceProvider`. The code for the rest of the app never mentions a vendor.

## Landed cost per slab

`landed cost = unit cost + (PO freight / total slabs on the PO)`

I split freight **by quantity**. Freight is driven by how many slabs go on the truck, not by their price, so a pricey slab should not pay more freight than a cheap one. The weakness is that 3cm slabs weigh about 50% more than 2cm slabs. If we had weights, splitting by weight would be better, and only `PurchaseOrderService::landedCost()` would change.

Delayed PO = status `ordered` or `in_transit` and ETA before today.

## Tests

`vendor/bin/phpunit` (or `docker compose run --rm app vendor/bin/phpunit`). The tests use a scripted fake AI, so they need no key. They cover tool data, role-specific field visibility, Purchasing-only draft creation and confirm-before-save.

## Cost estimate

Assumptions: Claude 3.5 Haiku on Bedrock at about $0.80 per 1M input tokens and $4.00 per 1M output tokens (check current prices). A question takes about 2 model calls, with about 3,500 input tokens (system prompt, tool definitions, history, tool results) and 300 output tokens.

- Per question: about $0.0028 + $0.0012 = **about $0.004**
- 50 staff x 20 questions x 22 days = 22,000 questions a month, so **about $90 a month**
- Redis and Postgres are small. Hosting is separate.

A larger model like Claude Sonnet costs about 4x more. Prompt caching of the system prompt and tool list would cut input cost further.

## Limits and next steps

- Bedrock is called with an API key over HTTP, not IAM signing. For production, use the AWS SDK with an IAM role.
- A draft PO takes unit costs from the last price paid (0 if none), and freight starts at 0.
- Product matching is a simple text search. If a name is ambiguous the assistant asks which one.
- There is no chat UI, only the API.
- The PO list is capped at 25 results.
