<?php
/**
 * @var callable $trans
 * @var array{
 *     recent:array{event_count:int,paths:list<array{path:string,count:int,total_ms:float,average_ms:float,max_ms:float,db_ms:float}>},
 *     daily:array{event_count:int,paths:list<array{path:string,count:int,total_ms:float,average_ms:float,max_ms:float,db_ms:float}>}
 * } $performanceWindows
 */
?>
<div class="stat-item performance-stat-item">
    <h3><?= $trans('Slow dynamic requests') ?></h3>
    <h4 class="performance-window-title"><?= $trans('Slow requests last hour') ?></h4>
    <?php
    $performance = $performanceWindows['recent'];
    require __DIR__ . '/performance-summary.php.inc';
    ?>
    <details class="performance-daily-history">
        <summary><?= $trans('Slow requests daily history', [
            '{{ count }}' => $performanceWindows['daily']['event_count'],
            '%count%' => $performanceWindows['daily']['event_count'],
        ]) ?></summary>
        <?php
        $performance = $performanceWindows['daily'];
        require __DIR__ . '/performance-summary.php.inc';
        ?>
    </details>
    <p class="technical-data"><?= $trans('Slow request threshold note') ?></p>
    <p class="technical-data"><?= $trans('Slow request privacy note') ?></p>
</div>
