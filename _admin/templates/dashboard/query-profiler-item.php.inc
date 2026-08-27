<?php
/**
 * @var callable $trans
 * @var callable $friendlyFilesize
 * @var string $basePath
 * @var string $csrfToken
 * @var array{active:bool,expires_at:int} $profilerState
 * @var array{
 *     request_count:int,
 *     query_count:int,
 *     contexts:list<array{
 *         client_group:string,
 *         agent:string,
 *         page_cache:string,
 *         cache_policy:string,
 *         query:string,
 *         cookies:string,
 *         purpose:string,
 *         fetch_mode:string,
 *         fetch_dest:string,
 *         count:int,
 *         query_count:int,
 *         average_queries:float,
 *         zero_query_percent:float,
 *         total_ms:float,
 *         db_ms:float
 *     }>,
 *     paths:list<array{method:string,path:string,count:int,total_ms:float,average_ms:float,max_ms:float,db_ms:float}>,
 *     templates:list<array{template:string,count:int,total_ms:float,average_ms:float,max_ms:float,path_count:int}>,
 *     recent:list<array{
 *         at:string,
 *         method:string,
 *         path:string,
 *         status:int,
 *         duration_ms:float,
 *         db_ms:float,
 *         query_count:int,
 *         truncated_queries:int,
 *         peak_memory_bytes:int,
 *         request_context:array{
 *             client_group:string,
 *             agent:string,
 *             page_cache:string,
 *             cache_policy:string,
 *             query:string,
 *             cookies:string,
 *             purpose:string,
 *             fetch_mode:string,
 *             fetch_dest:string
 *         },
 *         queries:list<array{template:string,time_ms:float}>
 *     }>
 * } $profile
 */
?>
<div class="stat-item query-profiler-stat-item" id="query-profiler">
    <h3><?= $trans('SQL query profiler') ?></h3>
    <?php if ($profilerState['active']): ?>
        <p class="stat-status query-profiler-active">
            <?= $trans('Query profiler active until') ?>
            <time datetime="<?= gmdate(\DateTimeInterface::ATOM, $profilerState['expires_at']) ?>" data-local-time>
                <?= gmdate('Y-m-d H:i:s', $profilerState['expires_at']) ?> UTC
            </time>
        </p>
    <?php else: ?>
        <p class="stat-status"><?= $trans('Query profiler inactive') ?></p>
    <?php endif; ?>

    <form class="query-profiler-actions" method="post" action="<?= $basePath ?>/_admin/ajax.php?action=register_query_profiler">
        <input type="hidden" name="csrf_token" value="<?= register_htmlencode($csrfToken) ?>">
        <button type="submit" name="command" value="start_300"><?= $trans('Start profiler for 5 minutes') ?></button>
        <button type="submit" name="command" value="start_900"><?= $trans('Start profiler for 15 minutes') ?></button>
        <?php if ($profilerState['active']): ?>
            <button type="submit" name="command" value="stop"><?= $trans('Stop profiler') ?></button>
        <?php endif; ?>
        <button type="submit" name="command" value="clear"<?= $profile['request_count'] === 0 ? ' disabled' : '' ?>><?= $trans('Clear profiler data') ?></button>
    </form>

    <p class="technical-data"><?= $trans('Query profiler privacy note') ?></p>
    <p class="technical-data"><?= $trans('Query profiler overhead note') ?></p>

    <?php if ($profile['request_count'] === 0): ?>
        <p class="stat-status"><?= $trans('No query profiles captured') ?></p>
    <?php else: ?>
        <p class="query-profiler-summary"><?= $trans('Profiler captured summary', [
            '{{ requests }}' => $profile['request_count'],
            '{{ queries }}'  => $profile['query_count'],
        ]) ?></p>

        <section class="query-profiler-section" aria-labelledby="query-profiler-context-title">
            <h4 id="query-profiler-context-title"><?= $trans('Request characteristics') ?></h4>
            <div class="query-profiler-table-wrap">
                <table class="query-profiler-table">
                    <thead>
                    <tr>
                        <th scope="col"><?= $trans('Client group') ?></th>
                        <th scope="col"><?= $trans('Request profile') ?></th>
                        <th scope="col"><?= $trans('Requests') ?></th>
                        <th scope="col"><?= $trans('SQL queries') ?></th>
                        <th scope="col"><?= $trans('SQL per request') ?></th>
                        <th scope="col"><?= $trans('Requests without SQL') ?></th>
                        <th scope="col"><?= $trans('Total time') ?></th>
                        <th scope="col"><?= $trans('Database time') ?></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($profile['contexts'] as $context): ?>
                        <tr>
                            <td><code><?= register_htmlencode($context['client_group']) ?></code></td>
                            <td><code><?= register_htmlencode(sprintf(
                                'agent=%s cache=%s policy=%s query=%s cookies=%s purpose=%s fetch=%s/%s',
                                $context['agent'],
                                $context['page_cache'],
                                $context['cache_policy'],
                                $context['query'],
                                $context['cookies'],
                                $context['purpose'],
                                $context['fetch_mode'],
                                $context['fetch_dest'],
                            )) ?></code></td>
                            <td><?= $context['count'] ?></td>
                            <td><?= $context['query_count'] ?></td>
                            <td><?= number_format($context['average_queries'], 2, '.', '') ?></td>
                            <td><?= number_format($context['zero_query_percent'], 1, '.', '') ?>%</td>
                            <td><?= number_format($context['total_ms'], 1, '.', '') ?> ms</td>
                            <td><?= number_format($context['db_ms'], 1, '.', '') ?> ms</td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="query-profiler-section" aria-labelledby="query-profiler-routes-title">
            <h4 id="query-profiler-routes-title"><?= $trans('Routes by total request time') ?></h4>
            <div class="query-profiler-table-wrap">
                <table class="query-profiler-table">
                    <thead>
                    <tr>
                        <th scope="col"><?= $trans('HTTP request') ?></th>
                        <th scope="col"><?= $trans('Requests') ?></th>
                        <th scope="col"><?= $trans('Total time') ?></th>
                        <th scope="col"><?= $trans('Average time') ?></th>
                        <th scope="col"><?= $trans('Maximum time') ?></th>
                        <th scope="col"><?= $trans('Database time') ?></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($profile['paths'] as $path): ?>
                        <tr>
                            <td><code><?= register_htmlencode($path['method'] . ' ' . $path['path']) ?></code></td>
                            <td><?= $path['count'] ?></td>
                            <td><?= number_format($path['total_ms'], 1, '.', '') ?> ms</td>
                            <td><?= number_format($path['average_ms'], 1, '.', '') ?> ms</td>
                            <td><?= number_format($path['max_ms'], 1, '.', '') ?> ms</td>
                            <td><?= number_format($path['db_ms'], 1, '.', '') ?> ms</td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="query-profiler-section" aria-labelledby="query-profiler-sql-title">
            <h4 id="query-profiler-sql-title"><?= $trans('SQL templates by total time') ?></h4>
            <div class="query-profiler-table-wrap">
                <table class="query-profiler-table query-profiler-sql-table">
                    <thead>
                    <tr>
                        <th scope="col"><?= $trans('SQL template') ?></th>
                        <th scope="col"><?= $trans('Executions') ?></th>
                        <th scope="col"><?= $trans('Total time') ?></th>
                        <th scope="col"><?= $trans('Average time') ?></th>
                        <th scope="col"><?= $trans('Maximum time') ?></th>
                        <th scope="col"><?= $trans('Routes') ?></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($profile['templates'] as $template): ?>
                        <tr>
                            <td><code><?= register_htmlencode($template['template']) ?></code></td>
                            <td><?= $template['count'] ?></td>
                            <td><?= number_format($template['total_ms'], 3, '.', '') ?> ms</td>
                            <td><?= number_format($template['average_ms'], 3, '.', '') ?> ms</td>
                            <td><?= number_format($template['max_ms'], 3, '.', '') ?> ms</td>
                            <td><?= $template['path_count'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <details class="query-profiler-recent">
            <summary><?= $trans('Recent profiled requests') ?></summary>
            <div class="query-profiler-request-list">
                <?php foreach ($profile['recent'] as $request): ?>
                    <details class="query-profiler-request">
                        <summary>
                            <code><?= register_htmlencode($request['method'] . ' ' . $request['path']) ?></code>
                            <span><?= number_format($request['duration_ms'], 1, '.', '') ?> ms · <?= $request['query_count'] ?> SQL</span>
                        </summary>
                        <p class="technical-data">
                            <time datetime="<?= register_htmlencode($request['at']) ?>" data-local-time><?= register_htmlencode($request['at']) ?></time>
                            · HTTP <?= $request['status'] ?>
                            · <?= $trans('Database time') ?> <?= number_format($request['db_ms'], 3, '.', '') ?> ms
                            · <?= $friendlyFilesize($request['peak_memory_bytes']) ?>
                            · <code><?= register_htmlencode(sprintf(
                                'client=%s agent=%s cache=%s policy=%s query=%s cookies=%s purpose=%s fetch=%s/%s',
                                $request['request_context']['client_group'],
                                $request['request_context']['agent'],
                                $request['request_context']['page_cache'],
                                $request['request_context']['cache_policy'],
                                $request['request_context']['query'],
                                $request['request_context']['cookies'],
                                $request['request_context']['purpose'],
                                $request['request_context']['fetch_mode'],
                                $request['request_context']['fetch_dest'],
                            )) ?></code>
                            <?php if ($request['truncated_queries'] > 0): ?>
                                · <?= $trans('Profiler queries omitted', ['{{ count }}' => $request['truncated_queries']]) ?>
                            <?php endif; ?>
                        </p>
                        <?php if ($request['queries'] === []): ?>
                            <p class="technical-data"><?= $trans('No SQL queries in request') ?></p>
                        <?php else: ?>
                            <div class="query-profiler-table-wrap">
                                <table class="query-profiler-table query-profiler-request-table">
                                    <thead>
                                    <tr>
                                        <th scope="col">#</th>
                                        <th scope="col"><?= $trans('Execution time') ?></th>
                                        <th scope="col"><?= $trans('SQL template') ?></th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ($request['queries'] as $index => $query): ?>
                                        <tr>
                                            <td><?= $index + 1 ?></td>
                                            <td><?= number_format($query['time_ms'], 3, '.', '') ?> ms</td>
                                            <td><code><?= register_htmlencode($query['template']) ?></code></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </details>
                <?php endforeach; ?>
            </div>
        </details>
    <?php endif; ?>
</div>
