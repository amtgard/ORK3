<?php
    $total = 0;
?>
<script type='text/javascript'>
    $(document).ready(function() {
        $( '#StartDate' ).datepicker({dateFormat: 'yy-mm-dd'});
        $( '#EndDate' ).datepicker({dateFormat: 'yy-mm-dd'});
    });
</script>
<div class='info-container' id='custom-filters'>
    <h3>Filters</h3>
    <form class='form-container' method='post' action='<?=UIR ?>Reports/custom/<?=$this->__session->kingdom_id ?>'>
        <div>
            <span>Kingdom:</span>
            <span>
                <select name="KingdomId">
                    <option value=''>All</option>
                    <?php foreach ((array)$kingdoms as $kingdom): ?>
                        <option value='<?php echo $kingdom['KingdomId']; ?>'><?php echo $kingdom['KingdomName']; ?></option>
                    <?php endforeach; ?>
                </select>
            </span>
        </div>
        <div>
            <span>Exclude Banned:</span>
            <span><input type='checkbox' name='excludeBanned' value='1' /></span>
        </div>
        <div>
            <span>Waivered:</span>
            <span><input type='checkbox' name='isWaivered' value='1' /></span>
        </div>
        <div>
            <span>Dues Paid:</span>
            <span><input type='checkbox' name='isDuesPaid' value='1' /></span>
        </div>
        <div>
            <span>Attendance:</span>
            <span>
                <select>
                    <option value='attn_morethan'>More than</option>
                    <option value='attn_lessthan'>Less than</option>
                </select>
                <input type='text' class='numeric-field' value='' name='numCredits' />
                credits gained
                <div>
                    <span>Minimum Weeks Attended</span>
                    <span>
                        <input type='text' class='numeric-field' value='' name='MinimumWeeklyAttendance' />
                    </span>
                </div>
                <div>
                    <span>Minimum Daily Attendance</span>
                    <span>
                        <input type='text' class='numeric-field' value='' name='MinimumDailyAttendance' />
                    </span>
                </div>
                <div>
                    <span>Monthly Credit Maximum</span>
                    <span>
                        <input type='text' class='numeric-field' value='' name='MonthlyCreditMaximum' />
                    </span>
                </div>
            </span>
        </div>
        <div>
            <span>Period</span>
            <span>
                <span>Date Range:</span>
                <span>
                    <input type='text' class='' value='' name='StartDate' id='StartDate' />
                    <input type='text' class='' value='' name='EndDate' id='EndDate' />
                </span>
                <div>
                    <span>Months</span>
                    <span>
                        <input type='text' class='numeric-field' value='' name='PerMonths' />
                    </span>
                </div>
                <div>
                    <span>Weeks</span>
                    <span>
                        <input type='text' class='numeric-field' value='' name='PerWeeks' />
                    </span>
                </div>
            </span>
        </div>
        <div>
            <span></span>
            <span><input type='submit' value='Update Details' name='Update' /></span>
        </div>
    </form>
</div>

<div class='info-container'>
    <h3>Custom Report</h3>
    <table class='information-table'>
        <thead>
            <tr>
                <?php if (!isset($this->__session->kingdom_id)) : ?>
                    <th>Kingdom</th>
                <?php endif; ?>
                <?php if (!isset($this->__session->park_id)) : ?>
                    <th>Park</th>
                <?php endif; ?>
                <th>Persona</th>
                <th>Attendance</th>
                <th>Credits</th>
                <th>Weeks Attended</th
            </tr>
        </thead>
        <tbody>
            <?php foreach ((array)$active_players as $k => $player): ?>
                <?php $total++; ?>
                <tr>
                    <?php if (!isset($this->__session->kingdom_id)) : ?>
                        <td><?=$player['KingdomName'] ?></td>
                    <?php endif; ?>
                    <?php if (!isset($this->__session->park_id)) : ?>
                        <td><?=$player['ParkName'] ?></td>
                    <?php endif; ?>
                    <td><?=$player['Persona'] ?></td>
                    <td class='data-column'><?=$player['WeeksAttended'] ?></td>
                    <td class='data-column'><?=$player['TotalCredits'] ?></td>
                    <td class='data-column'><?=$player['WeeksAttended'] ?></td>
                </tr>
            <?php endforeach; ?>
            <tr>
                <td colspan='<?=(3 + (!isset($this->__session->kingdom_id)?1:0) + (!isset($this->__session->park_id)?1:0)) ?></td>'></td>
                <td>Total: <?=$total ?></td>
            </tr>
        </tbody>
    </table>
</div>

