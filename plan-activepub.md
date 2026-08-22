# ActivityPub для Register: полный план реализации

Делаем полноценный blog-native ActivityPub-узел для Register, а не урезанный «посты куда-то отправляются». Сайт и авторы получат федеративные личности, публикации, подписки, ответы, реакции, reader, модерацию, перенос домена, диагностику и безопасную доставку. Публично включать модуль будем только после прохождения межсерверных тестов — федеративные ID нельзя потом безболезненно переделать.

## Зафиксированные архитектурные решения

- ActivityPub будет первым штатным опциональным модулем в `_extensions/activitypub`. Это соответствует [архитектуре модулей](_doc/decisions/0001-register-module-tiers.md).

- Никаких Redis, Node.js, демонов, WebSocket-серверов и внешних cron-процессов. PDO-база хранит очередь, lease, inbox/outbox, дедупликацию, backoff, кэши, лимиты и блокировки.

- Фоновая работа выполняется существующими короткими порциями через `register_shutdown_function`, как описано в [архитектуре Register](_doc/architecture.md).

- Сетевой запрос никогда не задерживает публикацию поста или ответ inbox. Сначала фиксируем работу в БД, отдаём HTTP-ответ, затем обрабатываем порцию после shutdown.

- SQLite, MySQL/MariaDB и PostgreSQL получают одинаковую семантику.

- `ext-openssl` не требуется. RSA реализуем через стабильный [`phpseclib/phpseclib` `^3.0.56`](https://packagist.org/packages/phpseclib/phpseclib): SHA-256 и явно PKCS#1 v1.5, а не стандартный для phpseclib PSS. Все обращения изолированы одним адаптером, чтобы перейти на 4.x после стабильного релиза без расползания namespace/API по модулю; ветку `4.0.x-dev` в production не используем.

- Для HTTPS на конфигурации без `ext-openssl` используем `ext-curl` и TLS из libcurl. Если на сервере нет ни cURL, ни рабочего HTTPS-транспорта, установщик честно не позволит активировать федерацию.

- `ext-sodium`, уже обязательный для Register, шифрует приватные RSA-ключи в БД. Главный ключ шифрования хранится в закрытом secret-файле с правами `0600`.

- HTML-сайт остаётся первичным источником. ActivityPub — ещё одно представление тех же публикаций рядом с RSS, canonical URL и обычной веб-страницей.

## Что получит пользователь

| Register | ActivityPub | Поведение |
|---|---|---|
| Весь сайт | `Service` или `Organization` | Общий аккаунт блога, например `@blog@example.org` |
| Автор | `Person` | Свой профиль, ключи, подписчики и публикации |
| Пост | `Article` | Заголовок, HTML, вложения, теги, автор и canonical URL |
| Пост в compatibility-режиме | `Note` | Для серверов, плохо показывающих `Article`; тип фиксируется при первой публикации |
| Постоянная страница | `Page` | Поддерживается, но рассылка страниц по умолчанию отключена |
| Ответ авторизованного автора | `Note` | Полноценный федеративный ответ |
| Удалённый ответ | Локальный комментарий | Проходит модерацию и сохраняет ссылку на автора и оригинал |
| Лайк | `Like` | Отображается в статистике взаимодействий |
| Эмодзи-реакция | `EmojiReact` | Сохраняется с возможностью корректного `Undo` |
| Репост | `Announce` | Отдельный счётчик и список источников |
| Снятие с публикации | `Delete` + `Tombstone` | Удалённые серверы получают удаление |

Анонимные локальные комментарии и реакции не федератятся: у них нет ActivityPub-актора и ключа. Федеративные действия сможет выполнять только авторизованный автор сайта.

### Многоавторский блог

- У сайта всегда есть общий actor.
- Каждый автор может отдельно включить свой `Person` actor.
- Пост принадлежит автору и публикуется от его имени.
- Общий actor сайта делает `Announce`, поэтому подписчики блога получают коллективную ленту всех авторов.
- Если автор не подключён к ActivityPub, владельцем публикации выступает actor сайта.
- ActivityPub-handle не связан с административным логином и не раскрывает его.

## Адреса и discovery

Появятся стабильные маршруты:

```text
/.well-known/webfinger
/.well-known/nodeinfo
/nodeinfo/2.1

/activitypub/inbox
/activitypub/actors/{stable-id}
/activitypub/actors/{stable-id}/inbox
/activitypub/actors/{stable-id}/outbox
/activitypub/actors/{stable-id}/followers
/activitypub/actors/{stable-id}/following
/activitypub/actors/{stable-id}/featured

/activitypub/objects/{stable-id}
/activitypub/activities/{stable-id}
/activitypub/keys/{stable-id}
```

Внешние ID будут случайными 128-битными идентификаторами и не будут зависеть от:

- числового ID записи;
- slug поста;
- имени автора;
- административного логина;
- заголовка сайта.

WebFinger связывает изменяемый `@handle@domain` с неизменяемым actor ID. Публичная страница автора получает `h-card`, `rel="me"` и ссылку на ActivityPub-представление. Пост получает `rel="alternate" type="application/activity+json"`.

Если Register установлен в подкаталоге, мастер настройки выдаст готовое правило для корневого `/.well-known/` в `.htaccess`. Без доступного WebFinger федерацию включить нельзя. WebFinger является фактической частью совместимости Fediverse, особенно для поиска `@user@domain`. [Документация WebFinger](https://docs.joinmastodon.org/spec/webfinger/).

Actor, inbox, outbox и коллекции реализуются согласно серверной части [ActivityPub](https://www.w3.org/TR/activitypub/) и словарю [ActivityStreams 2.0](https://www.w3.org/TR/activitystreams-core/).

## Публикация контента

Для каждого объекта формируется переносимое ActivityStreams-представление:

- `id`, `type`, `url`;
- `attributedTo`;
- `name`, `summary`, `content`;
- `published`, `updated`;
- `to`, `cc`, `audience`;
- `tag` с `Hashtag` и `Mention`;
- `attachment` с MIME-типом, размером, alt-текстом и размерами изображения;
- `replies`;
- язык содержимого;
- canonical URL.

Редактор получит панель «Федерация»:

- публиковать или не публиковать объект;
- полный текст или анонс со ссылкой;
- `Article` или compatibility-`Note`;
- Public или Unlisted;
- content warning;
- язык;
- предпросмотр именно того HTML/JSON, который увидят удалённые серверы.

Followers-only для обычных постов пока не изображаем: если статья публична на сайте, было бы нечестно объявлять её приватной в ActivityPub. Такая видимость появится только вместе с настоящим контролем доступа в самом Register.

### Жизненный цикл

- Первая публикация создаёт `Create`.
- Редактирование создаёт `Update`, сохраняя object ID.
- Снятие с публикации и удаление создают `Delete`.
- Старый ID начинает возвращать `Tombstone`.
- Повторная публикация после `Delete` получает новую федеративную incarnation и новый object ID: уничтоженный объект не «воскресает».
- Все активности имеют собственные неизменяемые ID.
- Снимок отправленного объекта сохраняется, поэтому повтор доставки подписывает те же данные.

Событие публикации и запись federation-outbox попадут в одну транзакцию БД. Пост не сможет оказаться опубликованным локально, но забытым федерацией.

## Inbox и социальные действия

Поддерживаем входящие:

- `Follow`, `Accept`, `Reject`, `Undo`;
- `Create`, `Update`, `Delete`;
- `Like`, `EmojiReact`, `Announce`;
- `Block`, `Flag`;
- `Move`;
- `Add`/`Remove` для featured-коллекций.

Inbox выполняет синхронно только дешёвую защиту:

1. Проверяет метод, Content-Type, размер, JSON-глубину и наличие подписи.
2. Применяет IP/domain rate limit.
3. Записывает ограниченный raw body и метаданные в БД.
4. Возвращает `202 Accepted`.
5. После ответа shutdown-runner проверяет подпись, получает actor при необходимости и выполняет действие.

Повторная доставка того же activity ID ничего не дублирует. `Update`, `Delete` и `Undo` принимаются только от владельца исходного объекта. Неизвестные типы безопасно игнорируются, но остаются видимыми в диагностике.

Удалённые ответы:

- превращаются в комментарии только при корректном `inReplyTo`;
- проходят HTML-санитайзер и антиспам;
- по умолчанию отправляются на модерацию;
- сохраняют actor URL, object URL, аватар и provenance;
- обновляются и удаляются вслед за удалённым объектом;
- не получают выдуманный email или IP.

Для этого в ядре появятся публичный `CommentImportService`, события создания/публикации/редактирования/tombstone и presentation-enricher. ActivityPub-модуль не будет напрямую обращаться к таблице комментариев.

## Подписки и reader

В админке можно будет:

- ввести `@actor@domain`;
- проверить WebFinger и подпись actor;
- посмотреть карточку до подписки;
- выбрать, от имени сайта или какого автора подписаться;
- подписаться, отписаться или заблокировать;
- читать хронологическую удалённую ленту;
- отвечать, ставить Like/EmojiReact и делать Announce;
- видеть ветку разговора и оригинальные URL.

Приватно адресованные `Note` попадут в отдельную закрытую вкладку, никогда не станут публичными комментариями и будут явно помечены как незашифрованные федеративные сообщения.

Это будет reader для владельца блога, а не публичный бесконечный social feed.

## Очередь на shared hosting

База данных выполняет роль брокера и координатора:

- inbox/outbox;
- delivery state;
- уникальность `(activity, inbox)`;
- следующий момент попытки;
- количество попыток;
- lease единственного runner;
- per-host throttle;
- circuit breaker;
- кэш actor/key;
- dead-letter;
- журнал результатов.

Ожидаемые сетевые ошибки не расходуют пять системных попыток общей очереди. ActivityPub-handler сам классифицирует результат и перепубликует свою следующую генерацию:

- `2xx` — успешно;
- `404/410` — endpoint удалён;
- `401/403` — обновить actor/key и повторить один раз;
- `429` — уважать `Retry-After`;
- `5xx`, DNS, timeout — exponential backoff;
- постоянная TLS/форматная ошибка — dead-letter.

Никаких `sleep()`. Время следующей попытки хранится в `available_at`.

Каждый handler делает не более одного самостоятельно повторяемого сетевого шага. Таймаут cURL вычисляется из оставшегося `QueueExecutionBudget`. В полноценном пятисекундном detached shutdown позже можно добавить ограниченный `curl_multi` на 2–4 адреса; односекундная порция остаётся последовательной.

Входящий ActivityPub POST сам создаёт HTTP-запрос и поэтому сразу запускает shutdown-обработку. Исходящая публикация также запускает первую доставку после ответа редактору. Если сайт полностью молчит, отложенные повторы ждут следующего запроса — это существующий и осознанный контракт Register.

Кнопка «Протолкнуть очередь» просто создаёт обычный безопасный административный запрос; работа всё равно выполняется штатным shutdown-runner.

## Криптография

- По одному RSA-2048 ключу на actor.
- SHA-256 + PKCS#1 v1.5.
- Исходящая legacy HTTP Signature для максимальной совместимости.
- Проверка и legacy-подписей, и RFC 9421 HTTP Message Signatures.
- Исходящая RFC 9421 реализация присутствует, но включается для проверенных peer-серверов с безопасным fallback.
- Проверяются `Digest`/`Content-Digest`, время создания, срок действия, method, target URI и Host.
- Signed GET используется для серверов с secure mode.
- Key ID версионируется; старый публичный ключ остаётся доступен в течение периода ротации.
- При ошибке подписи кэш actor/key один раз принудительно обновляется.
- Приватные ключи зашифрованы `sodium_crypto_secretbox`.
- Тесты принудительно запускают внутренний pure-PHP engine phpseclib, чтобы отсутствие `ext-openssl` не осталось случайно непроверенным.

Актуальная практика Mastodon всё ещё требует совместимости с legacy RSA-SHA256, одновременно двигаясь к RFC 9421, поэтому поддерживаем оба формата. [Документация Mastodon по безопасности ActivityPub](https://docs.joinmastodon.org/spec/security/).

## Безопасность

Будет выделен общий `SafeRemoteHttpClient` на основе уже существующих наработок Link Health:

- только HTTPS в production;
- запрет URL credentials;
- блокировка private, loopback, link-local и reserved IP;
- отклонение смешанных public/private DNS-ответов;
- закрепление проверенного IP в cURL;
- повторная DNS-проверка каждого redirect;
- запрет HTTPS → HTTP downgrade;
- ограничение redirects, размера ответа и времени;
- удаление Authorization/Cookie при смене origin;
- никакого произвольного JSON-LD context fetching;
- строгий HTML allowlist;
- запрет script/style/form/iframe/data-URL;
- защита от replay и oversized collections;
- type-specific проверка прав на Update/Delete/Undo/Move.

Удалённые аватары для публичных комментариев кэшируются локально с лимитами MIME, размера и разрешения. Мы не будем подсовывать посетителям hotlink, раскрывающий их IP чужому серверу. Неограниченное зеркалирование удалённых видео и изображений запрещено.

## Таблицы модуля

Планируются независимые переносимые таблицы:

- `register_ap_local_actor`;
- `register_ap_actor_key`;
- `register_ap_actor_alias`;
- `register_ap_content_object`;
- `register_ap_activity`;
- `register_ap_inbox_item`;
- `register_ap_delivery`;
- `register_ap_follow`;
- `register_ap_remote_actor`;
- `register_ap_remote_object`;
- `register_ap_interaction`;
- `register_ap_moderation_rule`;
- `register_ap_notification`.

Длинные URL хранятся полностью, а уникальные индексы строятся по SHA-256, чтобы не упираться в ограничения MySQL. Успешные raw inbox payload удаляются по retention policy; остаются нормализованное состояние, hash и аудит. Tombstone и identity-записи не очищаются обычным housekeeping.

## Администрирование

Мастер включения проверит:

- canonical HTTPS origin;
- доступность корневого WebFinger;
- base path и `.htaccess`;
- cURL/TLS;
- writable private-secret storage;
- БД и миграции;
- возможность сгенерировать и восстановить RSA-ключ;
- внешний self-fetch actor;
- signed inbox round-trip.

Dashboard покажет:

- локальные actors и ключи;
- followers/following;
- размер inbox/outbox;
- готовые, delayed и failed доставки;
- возраст самой старой работы;
- ошибки по доменам;
- время последнего shutdown-runner;
- block/silence rules;
- очередь модерации;
- размер кэша удалённых объектов;
- тест WebFinger, actor fetch и подписи.

Будут отдельные действия «Пауза», «Вывести actor из федерации» и «Отключить модуль». Пауза сохраняет публичные identity endpoints. Полное отключение после активации потребует явного decommission: отправить Delete, оставить tombstone и только затем выключить маршруты.

## Миграция и резервное копирование

- При первом включении старые публикации не рассылаются автоматически.
- Можно выбрать: «только новые», последние N или отмеченные вручную.
- Старые объекты всё равно появляются в outbox-коллекции без массовой рассылки.
- Смена handle создаёт alias, но не меняет actor ID.
- Смена домена выполняется через `Move` и взаимный `alsoKnownAs`.
- Старый домен должен оставаться доступным на период переноса.
- Backup включает таблицы ActivityPub, encrypted private keys и обязательный secret-файл.
- После восстановления выполняется проверка соответствия private/public keys.
- Потеря master key блокирует исходящие подписи и показывает аварийное состояние, но не генерирует молча новую личность.

## Этапы реализации

1. **ADR и protocol profile.** Замораживаем URI-схему, actors, состояния Follow/Delivery/Object, правила видимости, удаления, повторной публикации и migration. Готовим threat model и эталонные JSON-файлы.

2. **Публичные возможности ядра.** Author/Profile API, расширенный content descriptor, comment lifecycle/import API, reaction aggregate writer, общий SSRF-safe HTTP client, deadline-aware cURL и backup secret integration.

3. **Каркас модуля.** Manifest, миграции трёх СУБД, настройки, permissions, таблицы, repositories и pause/decommission lifecycle.

4. **Key vault и подписи.** phpseclib, зашифрованные ключи, legacy verification/signing, RFC 9421 verification/signing, key refresh и rotation.

5. **Discovery и read-only ActivityPub.** WebFinger, NodeInfo, actor, objects, activities, коллекции, content negotiation, HTML-профили и self-test.

6. **Transactional outbox и доставка.** `Create/Update/Delete`, sharedInbox fanout, delivery state machine, retries, throttling и журнал.

7. **Inbox и подписки.** Быстрый `202`, проверка подписи, Follow/Accept/Reject/Undo, actor cache, блокировки и дедупликация.

8. **Разговоры и взаимодействия.** Replies ↔ comments, Like, EmojiReact, Announce, Undo, mentions, moderation, Flag и уведомления.

9. **Reader и исходящие действия.** Follow из админки, хронологическая лента, ветки, ответы, реакции, репосты и приватные входящие Note.

10. **Операции и перенос.** Dashboard, queue inspection, manual shutdown trigger, key rotation, Move, aliases, экспорт, backup/restore, pause и decommission.

11. **Hardening и interoperability.** Нагрузочные, fuzz, security и shared-hosting тесты; затем тестовая федерация с Mastodon, GoToSocial, Akkoma, Misskey/Sharkey, WordPress ActivityPub, Ghost, WriteFreely и вторым Register.

12. **Документация и релиз.** Настройка `.well-known`, reverse proxy, модерация, privacy, восстановление ключей, смена домена и таблица известных особенностей peer-серверов.

Это внутренние этапы, не «MVP-релизы». До завершения последнего interoperability-gate акторы наружу не публикуются.

### Текущий статус реализации (2026-08-22)

- Этапы 1–10 реализованы в ветке `activitypub`: включая multi-author actors, транзакционный outbox, DB-очереди shutdown-runner, reader, полные локальные и удалённые lifecycle, Mention и локальные attachment metadata, dashboard, Move, backup/recovery и self-service только для собственного actor автора.
- Автоматическая часть этапа 11 реализована: SSRF/DNS pinning, legacy и RFC 9421 signatures, key-confusion preflight, pure-PHP RSA, crash recovery, idempotency, retention и строгий release-gate покрыты тестами. Локальный SQLite-прогон ActivityPub проходит полностью; MySQL/MariaDB и PostgreSQL остаются обязательными профилями CI для release-кандидата.
- Финальная локальная контрольная серия чистая: 787 unit-тестов / 10 842 assertions, 216 integration-тестов / 2 297 assertions, lint 1 119 PHP-файлов, PHPStan, Psalm, Phan, PHPMD, Rector, PHP 8.3–8.5 compatibility, Composer audit и dependency analysis. Production-сборка shared-hosting ZIP также проверена: в ней есть модуль, stable `phpseclib/phpseclib` 3.0.56, recovery/interoperability tools и runbooks, а dev-зависимостей нет.
- Живая peer-матрица этапа 11 ещё не проводилась и не считается пройденной. В репозитории есть только `.dist`-шаблоны. Активация требует точный хэш-связанный отчёт для Mastodon, GoToSocial, Akkoma, Misskey, WordPress ActivityPub, Ghost, WriteFreely и второго Register, поэтому поддельный или прошлый attestation не откроет публичные actors.
- Документация этапа 12 подготовлена: shared-hosting, `.well-known`, reverse proxy, очередь, moderation/privacy, backup/recovery, handle/Move/decommission и воспроизводимая процедура interoperability. Релиз остаётся намеренно заблокирован до настоящих внешних прогонов.

## Критерии готовности

Модуль считается готовым, когда:

- устанавливается на обычный shared hosting без Redis, cron, CLI, Node и `ext-openssl`;
- работает на SQLite, MySQL/MariaDB и PostgreSQL;
- публикация и inbox не ждут внешнюю сеть;
- после убийства PHP любая операция безопасно продолжается;
- повторная доставка не создаёт дублей;
- Create/Update/Delete/Undo работают в обе стороны;
- remote reply корректно проходит модерацию;
- SSRF, replay, key substitution и HTML-инъекции закрыты тестами;
- ключи восстанавливаются из backup;
- очередь остаётся ограниченной по времени и памяти;
- две установки Register полностью федератятся друг с другом;
- основная матрица Fediverse проходит автоматические и живые тесты.

Осознанно не включаем Mastodon REST API, универсальный OAuth/C2S для сторонних мобильных клиентов, глобальный поиск Fediverse, relay-crawling и «зашифрованный мессенджер». Это отдельные продукты; их отсутствие не урезает полноценную серверную федерацию блога.
