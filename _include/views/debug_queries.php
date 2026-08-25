<?php

declare(strict_types = 1);

/**
 * @var list<array{statement: string, template: string, time: float}> $saved_queries
 * @var callable $numberFormat
 */

?>
		<div id="debug">
			<table>
				<thead>
					<tr>
						<th class="tcl" scope="col">Time,&nbsp;ms</th>
						<th class="tcr" scope="col">Query</th>
					</tr>
				</thead>
				<tbody>
<?php

$query_time_total = 0.0;
$maximumTime = 0.0;
foreach ($saved_queries as $cur_query) {
    $query_time_total += $cur_query['time'];
    $maximumTime = max($maximumTime, $cur_query['time']);
}

foreach ($saved_queries as $cur_query) {

?>
					<tr>
						<td class="tcl" valign="top"><meter min="0" max="<?= register_htmlencode((string)max($maximumTime, 1.0E-12)) ?>" value="<?= register_htmlencode((string)$cur_query['time']) ?>"><?php echo $numberFormat($cur_query['time']*1000, true) ?></meter> <?php echo ($cur_query['time'] > 0.0 ? $numberFormat($cur_query['time']*1000, true) : '&#160;') ?></td>
                        <td valign="top" class="tcr"><code><?php echo register_htmlencode($cur_query['statement']) ?></code></td>
					</tr>
<?php

}

?>
					<tr class="totals">
						<td class="tcl"><em><?php echo $numberFormat($query_time_total*1000, true) ?></em></td>
						<td class="tcr"><em>Total query time</em></td>
					</tr>
				</tbody>
			</table>
			Peak memory = <?php echo $numberFormat(memory_get_peak_usage()); ?>,
            memory = <?php echo $numberFormat(memory_get_usage()); ?>,
            total queries = <?php echo count($saved_queries); ?>
		</div>
<?php
