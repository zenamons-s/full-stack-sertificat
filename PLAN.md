# Gift Certificates Platform — инженерный план

**Стек:** PHP 8.3 (Slim 4) · Go 1.22 · Next.js 15 · PostgreSQL 16 · Redis 7 · Docker Compose
**Формат документа:** аналитика → решения → фазы с Definition of Done → промпты для Codex

---

# ЧАСТЬ I. АНАЛИЗ

## 1.1. Что реально проверяется

Задание написано так, что «работает» — это порог допуска, а не критерий. Явно перечислены критерии оценки: организация проекта, читаемость, разделение логики, обработка ошибок, поддерживаемость. Отсюда следуют практические выводы:

| Наблюдение в задании | Что за этим стоит | Как отвечаем |
|---|---|---|
| «не нужен идеальный проект» | Проверяют вкус и приоритеты, а не объём | Меньше фич, но каждая доведена до конца |
| «принимайте решения самостоятельно и опишите в README» | Проверяют **обоснование**, а не сам выбор | ADR-секция в README: решение → альтернативы → почему |
| «насколько удачно разделена логика» | Ищут утечку бизнес-логики в контроллеры/ORM | Явные слои Domain / Application / Infrastructure / Http |
| «как обрабатываются ошибки» | Ищут единый контракт ошибок, а не `try/catch` россыпью | RFC 7807 + один ErrorHandler + доменные исключения |
| «6–10 часов» | Проверяют умение резать скоуп | Явный раздел «Не реализовано и почему» в README |
| Отдельный сервис на Go | Проверяют понимание конкурентности и фоновой обработки | Advisory lock, SKIP LOCKED, батчи, graceful shutdown |

**Главный риск:** переусложнить. CQRS, event sourcing, Kafka, микросервисная сага — это минус, а не плюс. Зрелость = минимальная архитектура, которая честно решает задачу и очевидно расширяется.

## 1.2. Неочевидные проблемы в постановке (то, что отличает senior-решение)

### Проблема 1. Статус — вычисляемое поле или хранимое?

Задание одновременно требует: (а) фильтрацию по статусу в списке, (б) отдельный Go-сервис, который раз в минуту меняет статус на `Expired`. Это противоречие: если статус вычисляется на лету (`expires_at < now()`), Go-сервис не нужен вовсе.

Значит статус **материализован** в БД. Но тогда возникает окно рассогласования до 60 секунд: сертификат уже истёк, а в БД всё ещё `active`.

Первая версия этого плана предлагала «эффективный статус» на чтении: если `status='active' AND expires_at<=now()`, отдавать клиенту `expired`. **Это ошибка, и её надо разобрать явно** — потому что она выглядит правильной ровно до момента, когда задумаешься о последствиях:

- если API сам вычисляет истечение при каждом чтении, **Go-сервис перестаёт влиять на наблюдаемое поведение системы**. Он превращается в фоновую запись в БД, которую невозможно увидеть через интерфейс. Ключевая часть задания становится незаметной;
- фильтр и отображение начинают считать по-разному в разных местах кода — правило «истёк» дублируется в SQL листинга, в сериализаторе и в воркере. Ровно то «размазывание логики», за которое снижают оценку;
- запись в БД и ответ API расходятся, что усложняет отладку и тесты.

**Итоговое решение: `status` — единственный источник истины для чтения. Никаких вычислений на лету.**

| Аспект | Правило |
|---|---|
| Листинг, карточка, фильтр | Только хранимый `status`. Badge и фильтр всегда согласованы |
| Владелец перехода `active → expired` | Только Go-сервис. Единственное место, где живёт правило |
| Окно рассогласования | До 60 с. Осознанная eventual consistency, описана в README |
| Защита от последствий | Любая **операция изменения** состояния дополнительно сверяется с `expires_at` в домене — нельзя отредактировать или погасить фактически истёкший сертификат, даже если в БД ещё `active` |

Это честный компромисс: чтение может отставать на минуту, запись — никогда. Именно так работают все системы с материализованными проекциями, и именно это стоит написать в README. Альтернативы, которые нужно упомянуть и отклонить: генерируемый столбец (`GENERATED ALWAYS AS`) не поддерживает `now()`, так как выражение не иммутабельно; вычисление во вьюхе убивает индексы по статусу; триггер на `SELECT` в Postgres невозможен.

Побочный эффект, который надо предусмотреть: интервал воркера в 60 с делает демонстрацию медленной. Для показа добавляем `WORKER_INTERVAL` в env — ревьюер может поставить `10s` и увидеть эффект быстрее.

### Проблема 2. Go ходит в БД напрямую или через REST API?

В задании сказано «взаимодействие компонентов через REST API». Буквальное прочтение → Go дёргает `PATCH /certificates/{id}` в цикле.

Разбор:

| Критерий | Прямой доступ к БД | Через REST |
|---|---|---|
| Производительность | Один `UPDATE ... WHERE` на батч | N HTTP-запросов, N транзакций |
| Атомарность | Есть | Нет, частичное применение при падении |
| Дублирование логики | Правило `expired` живёт в двух местах | Логика одна |
| Связанность со схемой | Go привязан к схеме БД | Go привязан к контракту API |
| Соответствие букве ТЗ | Частичное | Полное |

**Решение:** прямой доступ к БД + **выделенный служебный эндпоинт** как компромисс не берём — берём прямой доступ, но:
- Go **не владеет схемой**: миграции только в PHP-репозитории, Go читает уже существующие таблицы;
- Go трогает **ровно один переход** `active → expired` и только его;
- фраза «взаимодействие через REST» соблюдена для пары Frontend ↔ Backend, что и является клиент-серверным контуром;
- обоснование выносится в README отдельным ADR.

**Реализуем оба пути за флагом `SYNC_MODE`, дефолт — `db`.** Важно, как это подать: это не «не смог выбрать», а выбор, оформленный через порт. В коде есть интерфейс `Expirer` с двумя адаптерами — прямым и HTTP. В README формулировка должна быть однозначной: *«По умолчанию используется прямой доступ к БД — обоснование ниже. Реализация вынесена за интерфейс, поэтому добавлен и REST-адаптер: он показывает, что переключение стратегии не требует правок доменной логики»*. Стоит ≈40 строк и попутно демонстрирует dependency inversion в Go.

### Проблема 3. Что происходит при продлении истёкшего сертификата

Сценарий, который ревьюер проверит руками: сертификат стал `expired`, пользователь открывает его и меняет срок действия на будущую дату. Если `expired` объявлен терминальным статусом — дата обновится, а статус останется `expired`, и это будет выглядеть как баг. Если разрешить свободный переход — теряется смысл машины состояний.

**Решение:** переход `expired → active` разрешён **только как побочный эффект** валидного продления, в одном месте — в методе `Certificate::extendValidity()`:

```
если status = expired И новый expires_at > now()
    → status = active, в аудит пишется action=renewed
если status ∈ {redeemed, cancelled}
    → 409, эти статусы действительно терминальны
```

Пользователь не может выставить статус напрямую — статус всегда следствие действия, а не поле формы. Это и есть разница между «есть enum» и «есть машина состояний».

### Проблема 4. Инвалидация кеша из двух процессов

Redis-кеш списков наполняет PHP, а инвалидировать должны и PHP (CRUD), и Go (массовый expire). Классическая ошибка — `KEYS certificates:*` + `DEL`, что блокирует Redis.

**Решение:** ключ-поколение. `certificates:gen` — счётчик, ключи кеша содержат его значение: `certificates:list:{gen}:{hash(query)}`. Любая мутация делает `INCR certificates:gen` — старые ключи осиротели и истекают по TTL. Атомарно, O(1), работает из обоих сервисов.

Два обязательных уточнения, без которых кеш становится источником багов:

- **В хеш ключа входят все параметры, влияющие на выборку**, включая `trashed` и идентификатор пользователя. Сейчас пользователь один, но забытый скоуп — это утечка чужих данных при первом же расширении. Пишем `certificates:list:{gen}:{userId}:{hash}` сразу.
- **Падение Redis не должно ронять API.** Все обращения к кешу обёрнуты так, что ошибка логируется на уровне warning и запрос идёт в БД. Кеш — оптимизация, а не зависимость. Соответственно `/health` показывает Redis отдельным полем со статусом `degraded`, а не отдаёт 503 на весь сервис.

### Проблема 5. Конкурентное редактирование

Два пользователя открыли форму, оба сохранили — второй молча затирает первого. Плюс гонка «пользователь редактирует, а воркер в этот момент проставляет expired».

**Решение:** оптимистичная блокировка через `version integer`. Клиент присылает `version` в `PATCH`, несовпадение → `409 Conflict` с RFC 7807.

Воркер тоже инкрементирует версию — и это даёт неочевидное следствие: пользователь, державший форму открытой дольше минуты, получит 409 из-за фонового процесса, а не из-за другого человека. Поэтому в ответе 409 возвращается **актуальное состояние записи**, а сообщение различает два случая: «запись изменена другим пользователем» и «срок действия истёк, пока вы редактировали». Фронт по этому ответу предлагает перезагрузить форму без потери введённых данных. Именно такие детали отличают работающий механизм от механизма «для галочки».

### Проблема 6. Мягкое удаление ломает уникальность и индексы

Если `deleted_at IS NOT NULL` записи остаются, то любой `UNIQUE` начинает конфликтовать, а все индексы раздуваются мёртвыми строками.

**Решение:** partial-индексы `WHERE deleted_at IS NULL`, глобальный фильтр в репозитории (не в вызывающем коде), отдельный метод `findWithTrashed()` только для аудита. Воркер тоже обязан исключать удалённые.

### Проблема 7. Деньги и время

- Деньги: `numeric`/`float` в PHP → потеря точности при арифметике. **Храним `bigint` в минорных единицах** (копейки) + `currency char(3)`. Наружу отдаём и `price_minor`, и форматированную строку.
- Время: разные таймзоны у PHP, Go, Postgres и браузера. **Всё в UTC, тип `timestamptz`, ISO-8601 с `Z` в API.** Валидация «дата больше текущей» — через инжектируемый `ClockInterface`, иначе тест не написать.

### Проблема 8. Хранение JWT на фронте

`localStorage` → уязвим к XSS, и это первое, что смотрит внимательный ревьюер.

**Решение:** BFF-паттерн. Next.js Route Handler принимает логин, ходит в PHP, кладёт access/refresh в **httpOnly + SameSite=Lax + Secure** cookie. Браузерный код токена не видит. `middleware.ts` защищает роуты. Мутации идут через Route Handlers → CSRF закрыт SameSite + проверкой Origin.

### Проблема 9. Порядок старта контейнеров

`depends_on` без `condition` не ждёт готовности — приложение стартует раньше Postgres и падает. Миграции, запущенные из entrypoint нескольких реплик, гоняются между собой.

**Решение:** healthcheck у postgres/redis, `condition: service_healthy`, отдельный one-shot контейнер `migrator` с `restart: "no"`, остальные сервисы ждут `condition: service_completed_successfully`.

## 1.3. Матрица требований → артефакт

| # | Требование ТЗ | Где реализуется | Фаза |
|---|---|---|---|
| R1 | JWT-аутентификация, 1 пользователь | `Http/Action/Auth`, `JwtTokenService`, сидер | 3 |
| R2 | Поля сертификата | миграция `certificates` | 2 |
| R3 | CRUD | `Http/Action/Certificate/*`, `CertificateService` | 4 |
| R4 | Поиск по названию | `pg_trgm` + `ILIKE`, `ListQuery` | 4 |
| R5 | Фильтр по статусу | `ListQuery`, строго по хранимому `status` | 4 |
| R6 | Пагинация | offset-based + `meta` | 4 |
| R7 | Валидация | DTO + доменные инварианты | 3–4 |
| R8 | Go: раз в минуту, expired | `worker/internal/expirer` | 5 |
| R9 | Логирование изменений | `log/slog` JSON + таблица аудита | 5 |
| R10 | 4 страницы фронта | `app/(auth)`, `app/(dashboard)` | 6 |
| R11 | Загрузка / ошибки / подтверждение | Suspense, toast, AlertDialog | 6 |
| R12 | Расширяемая схема БД | миграции, enum-статусы, аудит | 2 |
| R13 | `docker compose up` | compose + healthchecks + migrator | 1, 7 |
| R14 | README | `README.md` + ADR | 9 |
| B1 | OpenAPI | `docs/openapi.yaml` + Swagger UI | 9 |
| B2 | Unit-тесты | PHPUnit, `go test`, Vitest | 8 |
| B3 | Healthcheck | все 4 сервиса | 7 |
| B4 | GitHub Actions | `.github/workflows/ci.yml` | 8 |
| B5 | Кеширование | Redis + ключ-поколение | 4 |
| B6 | Мягкое удаление | `deleted_at` + partial index | 2, 4 |
| B7 | Аудит изменений | `certificate_audit` | 4, 5 |

Покрытие: **все** обязательные + **все 7** бонусных пунктов.

## 1.4. Выбор технологий

**PHP: Slim 4 + PHP-DI + Doctrine DBAL/ORM.**
Laravel/Symfony сделают за нас каркас, но спрячут архитектуру за фреймворком — ревьюер увидит Eloquent-модель в контроллере и не поймёт, умеет ли кандидат в слои. Slim даёт PSR-7/PSR-15 и не навязывает ничего: структура кода = решение автора, что и оценивается. Doctrine — только DBAL + минимальный ORM для сущностей; репозитории свои, интерфейсы в Application-слое.

**Go: стандартная библиотека + `pgx/v5` + `slog`.**
Никаких фреймворков. Задача — тикер, транзакция, логи. Внешние зависимости: `pgx`, `godotenv` (опционально). Всё.

**Next.js 15 App Router + TypeScript strict + Tailwind + shadcn/ui + TanStack Query + react-hook-form + zod.**
Типы API-клиента генерируются из `openapi.yaml` (`openapi-typescript`) — контракт один, рассинхрон невозможен.

**PostgreSQL 16.** Нужны: `timestamptz`, `jsonb` для аудита, partial-индексы, `pg_trgm` для поиска, `SKIP LOCKED` и advisory locks для воркера. MySQL закрывает это хуже.

---

# ЧАСТЬ II. ПРОЕКТИРОВАНИЕ

## 2.1. Структура репозитория

```
gift-certificates/
├── backend/
│   ├── src/
│   │   ├── Domain/
│   │   │   ├── Certificate/
│   │   │   │   ├── Certificate.php              # сущность, инварианты в конструкторе
│   │   │   │   ├── CertificateStatus.php        # backed enum + переходы
│   │   │   │   ├── Money.php                    # value object
│   │   │   │   └── CertificateRepository.php    # интерфейс (порт)
│   │   │   ├── User/{User.php,UserRepository.php}
│   │   │   └── Exception/{DomainException,NotFound,Conflict,Validation}.php
│   │   ├── Application/
│   │   │   ├── Certificate/
│   │   │   │   ├── CertificateService.php
│   │   │   │   ├── Command/{CreateCertificate,UpdateCertificate}.php
│   │   │   │   ├── Query/ListCertificatesQuery.php
│   │   │   │   └── Dto/{CertificateDto,PaginatedResult}.php
│   │   │   ├── Auth/AuthService.php
│   │   │   ├── Audit/AuditRecorder.php
│   │   │   └── Port/{CacheInterface,ClockInterface,TokenIssuer}.php
│   │   ├── Infrastructure/
│   │   │   ├── Persistence/Doctrine/{DoctrineCertificateRepository,DoctrineUserRepository}.php
│   │   │   ├── Cache/RedisCache.php
│   │   │   ├── Security/{FirebaseJwtTokenIssuer,PasswordHasher}.php
│   │   │   └── Clock/SystemClock.php
│   │   └── Http/
│   │       ├── Action/
│   │       │   ├── Auth/{LoginAction,RefreshAction,MeAction}.php
│   │       │   └── Certificate/{List,Show,Create,Update,Delete}Action.php
│   │       ├── Middleware/{JwtAuthMiddleware,RequestIdMiddleware,RateLimitMiddleware,JsonBodyParser}.php
│   │       ├── Response/{JsonResponder,ProblemDetails}.php
│   │       ├── Request/{Validator,CreateCertificateRequest,...}.php
│   │       └── ErrorHandler.php
│   ├── config/{container.php,routes.php,settings.php,doctrine.php}
│   ├── migrations/VersionYYYYMMDDHHMMSS_*.php
│   ├── tests/{Unit,Integration}
│   ├── public/index.php
│   ├── bin/console                              # migrate, seed
│   ├── composer.json  phpunit.xml  phpstan.neon
│   └── Dockerfile
├── worker/
│   ├── cmd/expirer/main.go
│   ├── internal/
│   │   ├── config/config.go
│   │   ├── storage/{postgres.go,certificate.go}
│   │   ├── expirer/{service.go,service_test.go}
│   │   ├── apiclient/client.go                  # режим SYNC_MODE=api
│   │   ├── cache/redis.go                       # инвалидация поколения
│   │   └── health/server.go                     # :8081/healthz
│   ├── go.mod  go.sum
│   └── Dockerfile
├── frontend/
│   ├── src/
│   │   ├── app/
│   │   │   ├── (auth)/login/page.tsx
│   │   │   ├── (dashboard)/certificates/{page.tsx,new/page.tsx,[id]/edit/page.tsx}
│   │   │   ├── api/auth/{login,logout,refresh}/route.ts   # BFF
│   │   │   ├── layout.tsx  error.tsx  not-found.tsx
│   │   ├── components/{certificates/*,ui/*}
│   │   ├── lib/{api-client.ts,schemas.ts,server-fetch.ts,format.ts}
│   │   ├── types/api.d.ts                        # generated from openapi
│   │   └── middleware.ts
│   ├── package.json  tsconfig.json  next.config.ts
│   └── Dockerfile
├── docker/
│   ├── nginx/default.conf
│   └── postgres/init.sql                         # CREATE EXTENSION pg_trgm
├── docs/openapi.yaml
├── .github/workflows/ci.yml
├── docker-compose.yml
├── .env.example
└── README.md
```

## 2.2. Схема БД

```sql
CREATE TYPE certificate_status AS ENUM ('active','expired','redeemed','cancelled');

CREATE TABLE users (
    id            BIGSERIAL PRIMARY KEY,
    email         CITEXT      NOT NULL UNIQUE,
    password_hash TEXT        NOT NULL,
    created_at    TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE certificates (
    id          BIGSERIAL PRIMARY KEY,
    title       VARCHAR(255)       NOT NULL,
    price_minor BIGINT             NOT NULL CHECK (price_minor > 0),
    currency    CHAR(3)            NOT NULL DEFAULT 'RUB',
    expires_at  TIMESTAMPTZ        NOT NULL,
    status      certificate_status NOT NULL DEFAULT 'active',
    version     INTEGER            NOT NULL DEFAULT 1,
    created_by  BIGINT             REFERENCES users(id),
    created_at  TIMESTAMPTZ        NOT NULL DEFAULT now(),
    updated_at  TIMESTAMPTZ        NOT NULL DEFAULT now(),
    deleted_at  TIMESTAMPTZ,
    CONSTRAINT title_not_blank CHECK (length(btrim(title)) > 0)
);

-- горячий путь воркера
CREATE INDEX idx_cert_expiry_sweep ON certificates (expires_at)
    WHERE status = 'active' AND deleted_at IS NULL;
-- листинг и фильтр
CREATE INDEX idx_cert_list ON certificates (created_at DESC, id DESC)
    WHERE deleted_at IS NULL;
CREATE INDEX idx_cert_status ON certificates (status) WHERE deleted_at IS NULL;
-- поиск по названию
CREATE INDEX idx_cert_title_trgm ON certificates USING gin (title gin_trgm_ops)
    WHERE deleted_at IS NULL;

CREATE TABLE certificate_audit (
    id             BIGSERIAL PRIMARY KEY,
    certificate_id BIGINT      NOT NULL,
    actor_type     TEXT        NOT NULL,   -- 'user' | 'worker' | 'system'
    actor_id       BIGINT,
    action         TEXT        NOT NULL,   -- created|updated|deleted|restored|expired
    changes        JSONB       NOT NULL DEFAULT '{}'::jsonb,  -- {field:{old,new}}
    request_id     TEXT,
    created_at     TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_audit_cert ON certificate_audit (certificate_id, created_at DESC);
```

**Почему без FK у аудита:** аудит — это журнал фактов, а не связанные данные. Он должен пережить любое будущее жёсткое удаление сертификата (архивация, очистка по запросу субъекта данных), поэтому каскад тут вреден. В README это оформляется как осознанный компромисс: ценой ссылочной целостности покупается независимость журнала от жизненного цикла записи. Альтернатива — `ON DELETE SET NULL` — рассматривалась, но при мягком удалении она не даёт ничего, а при жёстком теряет привязку.

**Машина состояний:**

```
                 создание
                    ↓
                 active ──── user: погашение ──→ redeemed  (терминальный)
                  ↑ │
                  │ └────── user: отмена ──────→ cancelled (терминальный)
                  │ │
 user: продление  │ └── worker: expires_at ≤ now ──┐
 (expires_at>now) │                                 │
                  └────────── expired ←─────────────┘
```

Правила:
- статус **никогда не задаётся напрямую** из запроса — он следствие действия (`create`, `extendValidity`, `redeem`, `cancel`, автоистечение);
- `redeemed` и `cancelled` действительно терминальны, любая попытка изменить → 409;
- `active → expired` выполняет **только** воркер;
- `expired → active` возможен только через продление срока, см. §1.2 проблема 3;
- всё это заперто в `CertificateStatus::canTransitionTo()` + именованных методах сущности. В таблице нет ни одного `setStatus()`.

## 2.3. Контракт API

Базовый префикс `/api/v1`. Все ответы `application/json`, ошибки — `application/problem+json`.

| Метод | Путь | Auth | Описание |
|---|---|---|---|
| POST | `/auth/login` | — | `{email,password}` → `{access_token, refresh_token, expires_in}` |
| POST | `/auth/refresh` | — | обмен refresh на новую пару |
| GET | `/auth/me` | ✔ | текущий пользователь |
| GET | `/certificates` | ✔ | `search, status, page, per_page, sort` |
| POST | `/certificates` | ✔ | создание → `201 Location` |
| GET | `/certificates/{id}` | ✔ | один сертификат |
| PATCH | `/certificates/{id}` | ✔ | частичное обновление, требует `version` |
| DELETE | `/certificates/{id}` | ✔ | мягкое удаление → `204` |
| POST | `/certificates/{id}/restore` | ✔ | восстановление удалённого → `200` |
| GET | `/certificates/{id}/audit` | ✔ | история изменений |
| GET | `/health` | — | `{status, db, redis, version}` |
| GET | `/docs` | — | Swagger UI |

**Формат списка:**
```json
{
  "data": [ { "id": 1, "title": "…", "price_minor": 500000, "currency": "RUB",
              "price_formatted": "5 000,00 ₽", "expires_at": "2026-12-31T23:59:59Z",
              "status": "active", "version": 1,
              "created_at": "2026-08-05T10:00:00Z", "updated_at": "…" } ],
  "meta": { "page": 1, "per_page": 20, "total": 137, "total_pages": 7 }
}
```

**Формат ошибки (RFC 7807):**
```json
{
  "type": "https://api.local/problems/validation-error",
  "title": "Validation failed",
  "status": 422,
  "detail": "Request payload is invalid",
  "instance": "/api/v1/certificates",
  "request_id": "01J9...",
  "errors": { "price_minor": ["Must be greater than zero"],
              "expires_at": ["Must be in the future"] }
}
```

**Коды:** `400` мусор в запросе · `401` нет/протух токен · `403` нет прав · `404` не найдено · `409` конфликт версий или недопустимый переход статуса · `422` валидация · `429` rate limit · `500` внутренняя.

## 2.4. Алгоритм воркера

```
каждые 60s (и один раз сразу при старте):
  1. pg_try_advisory_lock(EXPIRER_LOCK_ID)   -- нет лока → пропуск цикла, лог warn
  2. цикл по батчам (limit 500):
       BEGIN
       SELECT id, title, version FROM certificates
        WHERE status='active' AND expires_at <= now() AND deleted_at IS NULL
        ORDER BY expires_at
        LIMIT 500 FOR UPDATE SKIP LOCKED
       -- если пусто: COMMIT, выход из цикла
       UPDATE certificates SET status='expired', version=version+1, updated_at=now()
        WHERE id = ANY($ids)
       INSERT INTO certificate_audit (…, actor_type='worker', action='expired', changes=…)
       COMMIT
       лог: {run_id, batch, count, ids_sample}
  3. если total > 0 → INCR certificates:gen в Redis
  4. pg_advisory_unlock
  5. лог итога: {run_id, total, duration_ms}
```

Обработка отказов: ошибка БД не роняет процесс — логируется и повторяется в следующем цикле; экспоненциальный backoff на переподключение; `SIGTERM/SIGINT` → `context.Cancel` → дожидаемся текущего батча → выход с кодом 0.

## 2.5. Переменные окружения

```env
# --- db ---
POSTGRES_DB=certificates
POSTGRES_USER=app
POSTGRES_PASSWORD=change_me
DATABASE_URL=pgsql://app:change_me@postgres:5432/certificates

# --- backend ---
APP_ENV=production            # local|production — влияет на детализацию ошибок
JWT_SECRET=change_me_min_32_chars
JWT_ACCESS_TTL=900
JWT_REFRESH_TTL=1209600
REDIS_URL=redis://redis:6379
CACHE_TTL=60
SEED_USER_EMAIL=admin@example.com
SEED_USER_PASSWORD=Password123!

# --- worker ---
WORKER_INTERVAL=60s           # для демонстрации можно поставить 10s
WORKER_BATCH_SIZE=500
WORKER_ADVISORY_LOCK_ID=982451653
SYNC_MODE=db                  # db|api
API_BASE_URL=http://nginx/api/v1
WORKER_API_EMAIL=worker@example.com      # сервисный аккаунт для SYNC_MODE=api
WORKER_API_PASSWORD=change_me
LOG_LEVEL=info

# --- frontend ---
API_INTERNAL_URL=http://nginx/api/v1   # server-side
NEXT_PUBLIC_APP_URL=http://localhost:3000
```

---

# ЧАСТЬ III. ПЛАН ПО ФАЗАМ

Правила работы: **одна фаза = одна ветка = один PR = один зелёный CI**. Не переходить к следующей, пока не выполнен DoD текущей. Для каждой фазы ниже дан промпт для Codex.

---

## Фаза 0 — Каркас репозитория и контракт API `~50 мин`

**Делаем:** инициализация репозитория, `.gitignore`, `.editorconfig`, `.env.example`, дерево каталогов, `README.md` со скелетом разделов и — главное — **полный `docs/openapi.yaml`**.

**Почему спецификация здесь, а не в конце:** во фразе 6 типы фронтенда генерируются из неё (`openapi-typescript`). Если писать спеку последней, фронт неизбежно будет опираться на руками написанные интерфейсы, а спека станет документацией задним числом — то есть враньём. Контракт пишется до кода: он же служит техзаданием для фаз 3–4 и позволяет проверять реализацию на соответствие, а не наоборот.

**DoD:** `openapi.yaml` проходит валидацию (`redocly lint` или `spectral`); в нём описаны все эндпоинты §2.3, схемы `Certificate`, `PaginatedCertificates`, `ProblemDetails`, примеры для 200/201/401/404/409/422.

> **Codex:** Создай структуру монорепозитория согласно дереву каталогов из PLAN.md §2.1. Добавь `.gitignore` (PHP vendor, Go bin, node_modules, .env, .next), `.editorconfig` (LF, utf-8, 4 пробела для PHP, tab для Go, 2 для TS), `.env.example` со всеми переменными из §2.5. Затем напиши **полный** `docs/openapi.yaml` (OpenAPI 3.1) по контракту из §2.3: все эндпоинты, `bearerAuth` security scheme, компоненты-схемы, формат ответа списка с `meta`, формат ошибки RFC 7807 с полем `errors`, примеры на каждый код ответа из таблицы кодов. Спека — источник истины для последующих фаз. Код приложений не пиши.

---

## Фаза 1 — Docker-инфраструктура `~50 мин`

**Делаем:** `docker-compose.yml` с сервисами `postgres`, `redis`, `php-fpm`, `nginx`, `migrator`, `worker`, `frontend`. Healthcheck на всех. Порядок старта через `service_healthy` / `service_completed_successfully`. Именованные volume для данных PG. Multi-stage Dockerfile-заглушки. Nginx-конфиг с проксированием на php-fpm.

**Почему сейчас:** инфраструктурные сюрпризы (сетевые имена, права, порядок старта) дешевле ловить на пустых контейнерах, чем на готовом коде.

**DoD:** `docker compose up -d` → все контейнеры `healthy`; `docker compose down -v && docker compose up` воспроизводит состояние с нуля; `curl localhost:8080/health` отвечает заглушкой.

> **Codex:** Реализуй `docker-compose.yml` и Dockerfile-ы согласно PLAN.md §2.1 и §2.5. Требования: postgres:16-alpine с healthcheck `pg_isready` и init-скриптом `CREATE EXTENSION pg_trgm, citext`; redis:7-alpine с healthcheck `redis-cli ping`; php:8.3-fpm-alpine (multi-stage: composer install → runtime), non-root пользователь; nginx:alpine с конфигом из `docker/nginx/`; отдельный сервис `migrator` (`restart: "no"`), от которого зависят backend и worker через `service_completed_successfully`; golang:1.22-alpine → distroless для воркера; node:22-alpine multi-stage для Next.js standalone-сборки. Все сервисы с healthcheck. Порты наружу: 3000 (frontend), 8080 (nginx). Пока приложений нет — сделай в Dockerfile-ах минимальные рабочие заглушки, отвечающие на healthcheck.

---

## Фаза 2 — Миграции и схема БД `~40 мин`

**Делаем:** Doctrine Migrations, миграции согласно §2.2, сидер тестового пользователя и ~30 демо-сертификатов (часть с прошедшей датой — чтобы воркер сразу было видно в деле), `bin/console migrate|seed`.

**DoD:** `docker compose run --rm migrator` создаёт схему идемпотентно; повторный запуск не падает; `\d+ certificates` показывает все индексы; сидер не дублирует данные при повторе.

> **Codex:** Настрой Doctrine Migrations в `backend/`. Создай миграции ровно по DDL из PLAN.md §2.2 (типы, CHECK-констрейнты, partial-индексы, GIN trgm по title, таблица аудита без FK). Реализуй `backend/bin/console` с командами `migrate`, `migrate:rollback`, `seed`. Сидер: пользователь из `SEED_USER_EMAIL`/`SEED_USER_PASSWORD` с Argon2id-хешем, сервисный аккаунт из `WORKER_API_EMAIL`/`WORKER_API_PASSWORD` (нужен воркеру в режиме `SYNC_MODE=api`) и 30 сертификатов — 20 активных с будущими датами, 5 с прошедшими датами и статусом `active` (для демонстрации воркера), 3 `redeemed`, 2 мягко удалённых. Сидер должен быть идемпотентным (проверка по email / по признаку демо-данных).

---

## Фаза 3 — Ядро backend: DI, ошибки, аутентификация `~2 ч`

**Делаем:**
- `public/index.php`, Slim + PHP-DI, автозагрузка PSR-4, конфиг из env с валидацией на старте (нет `JWT_SECRET` → падаем сразу, а не в рантайме)
- Доменные исключения и их маппинг на HTTP в едином `ErrorHandler` → RFC 7807; в `APP_ENV=production` никаких stacktrace
- `RequestIdMiddleware` (ULID в `X-Request-Id`, проброс в логи и в тело ошибки)
- Логирование Monolog в stdout, JSON-формат
- `AuthService`, `FirebaseJwtTokenIssuer` (HS256), access + refresh, `JwtAuthMiddleware`
- `RateLimitMiddleware` на `/auth/login` (Redis, 5 попыток/мин на IP)
- Сущность `User`, репозиторий

**DoD:** `POST /auth/login` с верными данными → 200 + пара токенов; с неверными → 401 problem+json; 6-я попытка → 429; `GET /auth/me` без токена → 401, с токеном → 200; протухший токен → 401 с `type=token-expired`.

> **Codex:** Собери ядро PHP-приложения в `backend/` на Slim 4 + PHP-DI 7 + Doctrine DBAL, строго по слоям из PLAN.md §2.1. Обязательно: (1) типизированный объект настроек, читающий env и падающий с внятной ошибкой при отсутствии обязательных переменных; (2) иерархия доменных исключений `DomainException` → `EntityNotFoundException`, `ValidationException`, `ConflictException`, `AuthenticationException`, и единый `ErrorHandler`, преобразующий их в RFC 7807 согласно таблице кодов §2.3; в production-режиме — без trace и без сообщений внутренних исключений; (3) `RequestIdMiddleware` с ULID; (4) Monolog в stdout в JSON с полем `request_id`; (5) JWT HS256 через `firebase/php-jwt`: access 15 мин, refresh 14 дней с ротацией и хранением jti отозванных в Redis; (6) `JwtAuthMiddleware`; (7) rate limit на логин через Redis. Никакой бизнес-логики сертификатов в этой фазе. PHPStan level 8 должен проходить.

---

## Фаза 4 — Backend: домен сертификатов, CRUD, список, кеш, аудит `~2.8 ч`

**Делаем:**
- `Certificate` с инвариантами в конструкторе (пустой title, `price_minor <= 0`, дата в прошлом — невозможные состояния не создаются в принципе), `Money`, `CertificateStatus` с проверкой переходов
- `CertificateRepository` (порт) + Doctrine-реализация с глобальным фильтром `deleted_at IS NULL`
- `CertificateService`: create / update / delete / get / list, транзакции, запись аудита в той же транзакции, инкремент `version`
- Actions: тонкие, только маппинг HTTP ↔ Application
- Валидация запросов: DTO + `Validator`, все ошибки собираются разом (не «первая попавшаяся»)
- Список: `search` (trigram ILIKE), `status` (по хранимому столбцу), `page/per_page` (max 100), `sort` (whitelist полей — защита от SQL-инъекции через ORDER BY)
- Redis-кеш списков с ключом-поколением, TTL 60 с, `INCR` на любой мутации
- `PATCH` с оптимистичной блокировкой → 409 при рассинхроне версии
- `GET /certificates/{id}/audit`
- `POST /certificates/{id}/restore` + параметр списка `?trashed=only|with|none` (по умолчанию `none`) — мягкое удаление без возможности отката бессмысленно как фича
- Продление истёкшего через `extendValidity()` с переходом `expired → active` и записью `action=renewed` (§1.2 проблема 3)
- Краевые случаи, которые обязательно проверит ревьюер: `page` за пределами диапазона → пустой `data` и корректная `meta`, а не 404; `per_page=0` или отрицательный → 422; `search` из одних пробелов → игнорируется; `status` с несуществующим значением → 422 со списком допустимых
- `updated_at` проставляется приложением, не триггером БД — поведение должно быть явным и одинаковым для PHP и Go

**DoD:** все 5 CRUD-эндпоинтов + аудит работают; `restore` возвращает запись в список и пишет `action=restored` в аудит; `restore` для неудалённой записи → 409; `price_minor: 0` → 422 с внятным полем; `expires_at` в прошлом → 422; пустой title → 422; несуществующий id → 404; `PATCH` со старой `version` → 409; `DELETE` → 204 и запись пропадает из списка, но остаётся в БД; повторный `GET` списка обслуживается из кеша (виден по логам), после `POST` — снова из БД; `?sort=id;DROP TABLE` → 400.

> **Codex:** Реализуй домен сертификатов согласно PLAN.md §2.2–2.3. Требования: (1) сущность `Certificate` защищает инварианты в конструкторе и именованных конструкторах — невалидный объект создать невозможно; `Money` как value object на `bigint`; `CertificateStatus` — backed enum с методом `canTransitionTo()` по схеме из §2.2; (2) интерфейс репозитория живёт в Domain, реализация — в Infrastructure; реализация всегда добавляет `deleted_at IS NULL`, кроме явного `withTrashed`; (3) `CertificateService` оркеструет транзакции и в той же транзакции пишет `certificate_audit` с diff вида `{field: {old, new}}`; (4) Action-классы содержат только маппинг запрос→команда и результат→ответ, ноль бизнес-логики; (5) валидация собирает **все** ошибки полей сразу и возвращает 422 в формате §2.3; (6) листинг: поиск через `title ILIKE '%'||:q||'%'` с trigram-индексом, фильтр статуса работает **строго по хранимому столбцу** `status`, пагинация с `per_page` максимум 100, сортировка только по whitelist полей; (7) кеш списков в Redis по схеме ключа-поколения из PLAN.md §1.2 проблема 4: ключ `certificates:list:{gen}:{userId}:{hash всех параметров запроса включая trashed}`, TTL из `CACHE_TTL`; **любая ошибка Redis перехватывается, логируется как warning и запрос идёт в БД — недоступность кеша не должна возвращать 5xx**; (8) `PATCH` требует поле `version`, при несовпадении — 409; (9) `POST /certificates/{id}/restore` снимает `deleted_at`, пишет в аудит `action=restored` и возвращает 409, если запись не была удалена; параметр списка `trashed=none|with|only` управляет видимостью удалённых; (10) статус **не принимается из запроса** — при обновлении `expires_at` на будущую дату сертификат со статусом `expired` возвращается в `active` через метод `extendValidity()` с записью `action=renewed`; попытка изменить `redeemed`/`cancelled` → 409; (11) чтение возвращает **только хранимый** `status`, никаких вычислений «истёк ли» на лету — владелец этого перехода один, и это воркер (см. §1.2 проблема 1). Покажи в ответе итоговый список созданных файлов.

---

## Фаза 5 — Go-сервис истечения `~1.5 ч`

**Делаем:** реализация алгоритма §2.4, конфиг из env, `pgx` пул, структурные логи, `/healthz` на `:8081`, graceful shutdown, режим `SYNC_MODE=api` как альтернативная реализация интерфейса `Expirer`, unit-тесты на выборку и на переход статуса.

**DoD:** при старте с сид-данными воркер за первый же цикл переводит 5 просроченных сертификатов в `expired`, инкрементирует `version` и пишет об этом JSON-лог; `certificate_audit` содержит записи с `actor_type=worker`; повторный цикл находит 0 и не пишет лишнего; при остановленном Postgres воркер логирует ошибку и продолжает попытки, а не падает; `docker compose stop worker` завершает процесс за <2 с с кодом 0; запуск двух реплик не приводит к двойной обработке.

> **Codex:** Напиши Go-сервис в `worker/` по алгоритму из PLAN.md §2.4. Только стандартная библиотека + `github.com/jackc/pgx/v5` + `github.com/redis/go-redis/v9`. Требования: (1) конфиг из env с валидацией на старте; (2) `time.Ticker` с интервалом `WORKER_INTERVAL`, первый прогон немедленно при старте; (3) весь прогон под `pg_try_advisory_lock` — если лок не получен, цикл пропускается с уровнем warn; (4) обработка батчами `WORKER_BATCH_SIZE` с `FOR UPDATE SKIP LOCKED` внутри транзакции, вместе с записью в `certificate_audit` (`actor_type='worker'`, `action='expired'`); (5) `log/slog` в JSON: на каждый прогон `run_id`, количество, длительность; ошибки — с уровнем error и без падения процесса; (6) `INCR certificates:gen` в Redis после успешных изменений; (7) HTTP-сервер `/healthz` на `:8081`, проверяющий соединение с БД; (8) graceful shutdown по SIGTERM/SIGINT через context, дожидаясь текущего батча; (9) интерфейс `Expirer` с двумя реализациями — `dbExpirer` и `apiExpirer` (ходит в REST с сервисным JWT), выбор по `SYNC_MODE`; (10) unit-тесты на построение запроса и на логику определения просроченных, с подменяемым источником времени. `go vet` и `golangci-lint run` — чисто.

---

## Фаза 6 — Frontend `~2.7 ч`

**Делаем:**
- Next.js 15 App Router, TS strict, Tailwind, shadcn/ui
- BFF: Route Handlers `/api/auth/login|logout|refresh`, httpOnly-cookie, автоматический refresh при 401 в серверном фетчере
- `middleware.ts` — редирект неавторизованных на `/login?next=…`
- `/login` — форма, react-hook-form + zod, отображение серверных ошибок по полям
- `/certificates` — server component с чтением фильтров из `searchParams` (состояние в URL — шарится и переживает перезагрузку), поиск с debounce 300 мс, фильтр статуса, пагинация, badge статусов, Suspense + skeleton
- `/certificates/new`, `/certificates/[id]/edit` — общая форма, zod-схемы зеркалят серверные правила, `version` в скрытом поле, отдельная обработка 409 («данные изменились, обновите страницу»)
- Удаление через AlertDialog с подтверждением, оптимистичное обновление + откат при ошибке
- Toast-уведомления, `error.tsx`, `not-found.tsx`, глобальный маппер `problem+json` → человеческое сообщение
- **Управление кешем данных (критично):** Next.js кеширует `fetch` и результаты server components по умолчанию — из-за этого статус, изменённый Go-воркером, не появится в интерфейсе, и вся работа над воркером будет невидима. Список запрашивается с `cache: 'no-store'`, страница помечена `export const dynamic = 'force-dynamic'`, после мутаций вызывается `revalidatePath`. Дополнительно — авто-refetch списка раз в 30 с через TanStack Query, чтобы переход в `expired` был виден без перезагрузки
- **Таймзоны:** `<input type="datetime-local">` отдаёт локальное время без зоны. Перед отправкой конвертируем в UTC ISO-8601, при загрузке формы — обратно в локальное. Отдельная пара функций в `lib/format.ts` + юнит-тест на них: без этого пользователь в UTC+3 будет ловить «дата должна быть в будущем» на завтрашней дате
- Корзина: фильтр «показать удалённые» + кнопка восстановления
- Типы API из `openapi.yaml` через `openapi-typescript`

**DoD:** полный цикл логин → список → создать → отредактировать → удалить → восстановить проходит в браузере без ошибок в консоли; сертификат с истёкшей датой самостоятельно меняет badge на `expired` в открытой вкладке в течение ~90 с — это и есть визуальное доказательство работы Go-сервиса, поэтому проверяется отдельно; продление истёкшего сертификата возвращает его в `active`; дата, введённая в форме, сохраняется без сдвига на часовой пояс; отключение backend показывает понятную ошибку, а не белый экран; невалидная форма подсвечивает конкретные поля сообщениями с сервера; фильтры сохраняются при F5 и при копировании ссылки; `npm run build` и `tsc --noEmit` чисто.

> **Codex:** Реализуй фронтенд в `frontend/` на Next.js 15 (App Router) + TypeScript strict + Tailwind + shadcn/ui + TanStack Query + react-hook-form + zod, по описанию фазы 6 в PLAN.md. Критично: (1) JWT только в httpOnly+SameSite=Lax cookie, выставляемой Route Handler'ом — токен недоступен клиентскому JS; (2) серверный фетчер централизованно обрабатывает 401 → пробует refresh → при неудаче редиректит на логин; (3) состояние списка (search/status/page) живёт в `searchParams`, а не в useState; (4) единый модуль разбора `application/problem+json`, раскладывающий `errors` по полям формы через `setError`; (5) 409 обрабатывается отдельным сообщением о конфликте версий; (6) skeleton через Suspense, toast на успех/ошибку, AlertDialog на удаление; (7) типы ответов API импортируются из сгенерированного `types/api.d.ts`, ручных дублей интерфейсов нет; (8) **кеш Next.js отключён для данных списка** — `cache: 'no-store'`, `export const dynamic = 'force-dynamic'`, `revalidatePath` после мутаций, плюс `refetchInterval: 30000` в TanStack Query, иначе изменения статуса от фонового воркера не будут видны в UI; (9) фильтр «показать удалённые» и кнопка восстановления. Дизайн — сдержанный и аккуратный, без излишеств.

---

## Фаза 7 — Сборка воедино, healthcheck, e2e-проверка `~50 мин`

**Делаем:** финальные Dockerfile (multi-stage, non-root, минимальные образы), healthcheck для php-fpm (`php-fpm-healthcheck` или `/health` через nginx), для worker (`/healthz`), для frontend (`/api/health`); `docker compose up` с нуля на чистой машине; сквозной прогон сценария; фикс CORS/сетевых имён.

**DoD:** на чистом клоне `cp .env.example .env && docker compose up` → через ≤2 мин всё `healthy`, фронт открывается, логин тестовым пользователем работает, воркер отработал первый цикл. Проверено дважды с `docker system prune`.

> **Codex:** Доведи Docker-сборку до продакшн-качества: multi-stage везде, non-root пользователи, `.dockerignore` для каждого сервиса, healthcheck на всех четырёх приложениях, `restart: unless-stopped`, ограничение логов (`json-file`, max-size 10m). Прогони полный цикл `docker compose down -v && docker compose up --build` и убедись, что все сервисы переходят в healthy и сквозной сценарий логин→CRUD работает. Исправь всё, что всплывёт.

---

## Фаза 8 — Тесты и CI `~1.5 ч`

**Тестируем то, где живёт риск, а не ради процента покрытия:**
- **PHPUnit unit:** инварианты `Certificate`, `Money`, переходы `CertificateStatus`, все правила валидации, `CertificateService` с in-memory репозиторием и фейковыми часами, маппинг исключений в ErrorHandler
- **PHPUnit integration:** login → 200/401, CRUD happy path, 422 на каждое правило, 409 на конфликт версий, 404, пагинация и поиск на реальном Postgres (отдельная тестовая БД, транзакция с откатом)
- **Go:** логика определения просроченных, поведение при пустой выборке, батчинг
- **Vitest:** zod-схемы, парсер problem+json, форматирование денег/дат
- **CI:** три параллельные джобы — PHP (`phpstan level 8` + `php-cs-fixer --dry-run` + phpunit с сервисом postgres), Go (`vet` + `golangci-lint` + `test -race`), Node (`tsc --noEmit` + `eslint` + `build`); четвёртая джоба — `docker compose build` для проверки, что образы собираются

**DoD:** бейдж CI зелёный на `main`; `phpstan level 8` без ошибок; `go test -race` без гонок.

> **Codex:** Напиши тесты по описанию фазы 8 PLAN.md и `.github/workflows/ci.yml` с четырьмя джобами (php, go, node, docker-build), запускающимися параллельно на push и pull_request. Для PHP-интеграционных тестов подними `services: postgres` в GitHub Actions и используй отдельную тестовую БД с откатом транзакции после каждого теста. Фейковые часы — через реализацию `ClockInterface`, никаких `sleep` в тестах. Добавь кеширование зависимостей (composer, go modules, npm) в actions. Цель — не покрытие ради покрытия, а тесты на бизнес-правила и граничные случаи.

---

## Фаза 9 — Сверка контракта, README, финальная полировка `~1 ч`

**Делаем:**
- **Сверка реализации со спекой из фазы 0** — прогнать `schemathesis run docs/openapi.yaml` или вручную сверить каждый эндпоинт; расхождения чинить в коде, а не в спеке (спека — контракт). Подключить Swagger UI на `/docs`, перегенерировать типы фронта
- README:
  1. Что это + скриншот списка
  2. Запуск в одну команду + тестовые креды + список URL (frontend, api, swagger)
  3. Архитектура: диаграмма компонентов, слои backend, почему так
  4. **ADR-раздел** — 11 решений в формате «контекст → решение → альтернативы → последствия»: (1) материализованный статус vs вычисляемый на лету и почему отказались от второго, (2) окно eventual consistency в 60 с и защита на записи, (3) Go напрямую в БД vs через REST, (4) переход `expired → active` только через продление, (5) Slim вместо Laravel/Symfony, (6) деньги в minor units, (7) оптимистичная блокировка и её взаимодействие с воркером, (8) ключ-поколение для инвалидации кеша, (9) кеш как необязательная зависимость с деградацией, (10) httpOnly cookie вместо localStorage, (11) мягкое удаление с partial-индексами и аудит без FK
  5. Схема БД + пояснение к индексам
  6. **Сценарий проверки за 5 минут** — пошаговый маршрут для ревьюера: логин тестовыми кредами → в сиде есть 5 сертификатов с истёкшей датой и статусом `active` → `docker compose logs -f worker` → в течение минуты видны JSON-логи перехода → обновление списка показывает `expired` → вкладка «История изменений» показывает записи с `actor_type=worker`. Без этого блока работа над Go-сервисом рискует остаться незамеченной
  7. Команды разработчика (миграции, тесты, линтеры)
  8. **Что не реализовано и как планировалось** — честно и конкретно
- Финальный проход: удалить закомментированный код, TODO, консольные логи; проверить, что `.env` не в git; вычитать сообщения об ошибках

**DoD:** README читается человеком, который видит проект впервые, и он запускает проект с первой попытки; Swagger UI открывается и «Try it out» работает.

> **Codex:** Сверь фактическое поведение API с `docs/openapi.yaml`, написанной в фазе 0: пройди по каждому эндпоинту, проверь коды ответов, имена полей, форматы дат и структуру ошибок. Любое расхождение исправляй в коде, а не в спецификации — она контракт. Обнови спеку только если в ней объективная ошибка, и отметь это в ответе. Подключи Swagger UI на `/docs`, перегенерируй `frontend/src/types/api.d.ts`. Затем напиши `README.md` по структуре фазы 9: обязательно включи раздел «Сценарий проверки за 5 минут» с командами и ожидаемым выводом логов воркера; особое внимание разделу ADR — для каждого решения контекст, выбор, рассмотренные альтернативы и последствия, коротко и по делу. Добавь раздел «Известные ограничения и что бы я сделал дальше». Тон — деловой, без маркетинга. Пройдись по репозиторию и вычисти отладочный мусор, TODO и закомментированный код.

---

## Сводка по времени

| Фаза | Часы |
|---|---|
| 0. Каркас + OpenAPI-контракт | 0.8 |
| 1. Docker-инфраструктура | 0.8 |
| 2. Миграции и схема | 0.7 |
| 3. Ядро backend + auth | 2.0 |
| 4. Домен, CRUD, кеш, аудит, restore | 2.8 |
| 5. Go-воркер | 1.5 |
| 6. Frontend | 2.7 |
| 7. Сборка и e2e | 0.8 |
| 8. Тесты и CI | 1.5 |
| 9. Сверка контракта + README | 1.0 |
| **Итого** | **≈14.6** |

С Codex время кодогенерации сокращается примерно вдвое, но не время ревью и отладки — закладывайте 8–10 часов собственного участия.

## О превышении заявленных 6–10 часов

Скоуп берётся полный, включая все семь бонусных пунктов. Это осознанное решение, и его нужно **явно проговорить в README**, иначе оно читается как неумение оценивать сроки:

> Задание оценено в 6–10 часов. Базовая часть уложилась в этот интервал; дополнительные пункты (OpenAPI, тесты, CI, кеширование, мягкое удаление, аудит) реализованы сверх него, поскольку они перечислены в разделе «что будет плюсом». Если требовался строго ограниченный по времени срез — минимальная версия соответствует коммитам до тега `v0.1-mvp`.

Практический ход: **поставить тег `v0.1-mvp` после фазы 7**, когда работает всё обязательное. Тогда у ревьюера есть выбор, что смотреть, а у вас — доказательство, что базовая часть уложилась в срок. Это дешевле любых объяснений.

**Страховочный порядок отсечения** — только если время закончится физически, а не по плану: `SYNC_MODE=api` → Vitest → refresh-токены → Redis-кеш. Всё несделанное описывается в README с указанием, как планировалось реализовать: задание прямо поощряет такую честность.

---

# ЧАСТЬ IV. КАК ВЕСТИ РАБОТУ С CODEX

1. **Положите этот файл в корень репозитория** как `PLAN.md` и начинайте каждую сессию с `Прочитай PLAN.md, мы работаем над фазой N`. Codex теряет контекст между сессиями — файл в репозитории его восстанавливает.
2. **Одна фаза — одна сессия.** Не давайте задачу «сделай весь проект»: агент начнёт срезать углы в середине и вы не заметите, где именно.
3. **Проверяйте DoD руками**, а не по отчёту агента. Особенно: 422 на каждое правило валидации, 409 на конфликт, факт попадания в кеш, реальное срабатывание воркера на сид-данных.
4. **Тесты как страховка от регрессий агента.** После фазы 8 любое последующее изменение проверяется CI — это единственный надёжный способ не дать агенту тихо сломать готовое.
5. **Коммит после каждой фазы**, ветка на фазу. Если агент ушёл не туда — `git reset --hard`, уточните промпт, повторите. Дешевле, чем чинить.
6. **Не позволяйте менять решения из Части II** без вашего согласия. Если агент предлагает Laravel вместо Slim или `float` вместо `bigint` — это дрейф, откатывайте.
7. **Ревьюйте сгенерированный код на предмет «размазывания логики»**: бизнес-правило, всплывшее в Action-классе или в React-компоненте, — главный дефект, который будет стоить оценки.

## Красные флаги в выводе агента

Если видите это — откатывайте, не спорьте с агентом:

| Симптом | Почему плохо |
|---|---|
| `if ($cert->expires_at < now())` где-либо кроме воркера и `extendValidity()` | Правило истечения расползлось |
| `setStatus()` / статус в теле запроса | Машина состояний превратилась в поле |
| SQL-строки в Action-классе или в `CertificateService` | Протекла инфраструктура |
| `float`/`string` для цены | Потеря точности |
| `new DateTime()` внутри домена | Невозможно тестировать |
| `try { } catch (\Exception $e) { return 500; }` | Убита вся система ошибок |
| `localStorage.setItem('token', …)` | Потерян весь смысл BFF |
| Исключение из Doctrine, всплывшее в HTTP-ответ | Нет границы слоя |
| `KEYS`/`SCAN` для инвалидации кеша | Отменяет схему поколений |
| Тест вида `assertTrue(true)` или тест на геттеры | Имитация покрытия |
