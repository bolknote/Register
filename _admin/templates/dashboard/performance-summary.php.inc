<?php
/**
 * @var callable $trans
 * @var array{
 *     event_count:int,
 *     paths:list<array{path:string,count:int,total_ms:float,average_ms:float,max_ms:float,db_ms:float}>
 * } $performance
 */
?>
<?php if ($performance['paths'] === []): ?>
    <p class="stat-status"><?= $trans('No slow requests recorded') ?></p>
<?php else: ?>
    <p class="technical-data"><?= $trans('Slow requests summary', [
        '{{ count }}' => $performance['event_count'],
        '%count%' => $performance['event_count'],
    ]) ?></p>
    <ul class="stat-summary-list">
        <?php foreach ($performance['paths'] as $path): ?>
            <li>
                <code><?= htmlspecialchars($path['path'], ENT_QUOTES, 'UTF-8') ?></code>
                <span class="technical-data"><?= $trans('Slow request metrics', [
                    '{{ count }}' => $path['count'],
                    '{{ total }}' => number_format($path['total_ms'], 1, '.', ''),
                    '{{ average }}' => number_format($path['average_ms'], 1, '.', ''),
                    '{{ maximum }}' => number_format($path['max_ms'], 1, '.', ''),
                    '{{ database }}' => number_format($path['db_ms'], 1, '.', ''),
                ]) ?></span>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>
