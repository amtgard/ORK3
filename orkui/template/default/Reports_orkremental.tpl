<link rel="stylesheet" href="<?=HTTP_TEMPLATE?>default/style/reports.css?v=<?=filemtime(__DIR__.'/style/reports.css')?>">
<link rel="stylesheet" href="<?=HTTP_TEMPLATE?>default/orkremental/orkremental.css?v=<?=filemtime(__DIR__.'/orkremental/orkremental.css')?>">

<div class="rp-root">
	<div class="rp-header">
		<div class="rp-header-left">
			<div class="rp-header-icon-title">
				<i class="fas fa-dice-d20 rp-header-icon"></i>
				<h1 class="rp-header-title">ORKremental</h1>
			</div>
		</div>
	</div>
	<div class="rp-context">
		<i class="fas fa-info-circle rp-context-icon"></i>
		<span>Welcome to ORKremental, a perfectly normal simulation of playing Amtgard.</span>
	</div>
	<div class="rp-content">

		<div id="orkr-root">
			<div class="w3-margin">
				<div style="width: 1220px; height: 600px">
					<div class="panel w3-padding" style="width: 300px; height: auto; float: left">
						<span id="deathText">
							<div style="font-size: large; color: red">You've burned out and gone mundane.</div>
							<div class="sidebar-element" style="color: gray">Your time is nearly up — use the Relic to reroll before you retire for good.</div>
						</span>

						<div class="" style="font-size: large">Age <span id="ageDisplay">14</span> Day <span id="dayDisplay">0</span></div>
						<div class="sidebar-element" style="color: gray">Lifespan: <span id="lifespanDisplay">70</span> years</div>

						<button id="pauseButton" class="w3-button button sidebar-element" onClick="setPause()">Pause</button>

						<span id="automation" class="inline" style="margin-left: 8px">
							<span>
								<div class="inline">Auto-advance</div>
								<input type="checkbox" class="inline" id="autoPromote">
							</span>
							</br>
							<span>
								<div class="inline">Auto-train</div>
								<input type="checkbox" class="inline sidebar-element" id="autoLearn">
							</span>
						</span>

						<div style="font-size: large" id="coinDisplay">
							<span></span>
							<span></span>
							<span></span>
							<span></span>
						</div>
						<div style="color: gray">Treasury</div>

						<ul class="sidebar-element" style="padding-left: 20px;">
							<li><span style="color: rgb(9, 160, 230)">Net/day: </span ><b id="signDisplay"></b><span id="netDisplay">
								<span></span>
								<span></span>
								<span></span>
								<span></span>
							</span></li>
							<li><span style="color: green">Income/day: </span><span id="incomeDisplay">
								<span></span>
								<span></span>
								<span></span>
								<span></span>
							</span></li>
							<li><span style="color: red">Expense/day: </span><span id="expenseDisplay">
								<span></span>
								<span></span>
								<span></span>
								<span></span>
							</span></li>
						</ul>

						<span id="quickTaskDisplay">
							<div style="width: 230px" class="small-margin inline job progress-bar progressBar">
								<div class="progress-fill progressFill" style="background-color:rgb(225, 165, 0)"></div>
								<div class="progress-text name">Task</div>
							</div>
							<div class="sidebar-element" style="color: gray">Current job</div>

							<div style="width: 230px" class="small-margin skill progress-bar progressBar">
								<div class="progress-fill progressFill" style="background-color:rgb(225, 165, 0)"></div>
								<div class="progress-text name">Task</div>
							</div>
							<div class="sidebar-element" style="color: gray">Current skill</div>
						</span>

						<div style="font-size: large"><span style="color: rgb(15, 105, 207)">Morale: </span><span id="happinessDisplay"></span></div>
						<div style="color: gray" class="sidebar-element">Affects all xp gain</div>

						<span id="evilInfo">
							<div style="font-size: large"><span style="color: rgb(200, 0, 0)">Corruption: </span><span id="evilDisplay"></span></div>
							<div style="color: gray" class="sidebar-element">Affects Anti-Paladin xp gain</div>
						</span>

						<!--
						<span id="scheduling">
							<div style="font-size: large">Scheduling</div>
							<div style="color: gray" class="sidebar-element">Schedule time to jobs/skills</div>
							<span style="height: 30px">
								<p style="width: 5px">Jobs</br>(32%)</p>
								<input id="schedulingSlider" class="slider" type="range" min="0" max="100" value="0">
								<p style="width: 5px">Skills</br>(68%)</p>
							</span>
						</span>
						-->

						<span id="timeWarping">
							<div style="font-size: large"><span style="color: rgb(204, 34, 219)">Crown Time: </span><span id="timeWarpingDisplay"></span></div>
							<div style="color: gray">Affects game speed</div>
							<button id="timeWarpingButton" class="w3-button button sidebar-element" style="margin-top: 5px; width:150px" onClick="setTimeWarping()">Enable Crown Time</button>
						</span>
					</div>

					<div class="panel w3-margin-left" style="width: 900px; height: 40px; float: left">
						<div class="w3-button w3-bar-item tabButton" id="jobTabButton" onClick="setTab(this, 'jobs')">Classes</div>
						<div class="w3-button w3-bar-item tabButton" onClick="setTab(this, 'skills')">Training</div>
						<div class="w3-button w3-bar-item tabButton" id="shopTabButton" onClick="setTab(this, 'shop')">Quartermaster</div>
						<div class="w3-button w3-bar-item tabButton" id="rebirthTabButton" onClick="setTab(this, 'rebirth')">The Relic</div>
						<div class="w3-button w3-bar-item tabButton" onClick="setTab(this, 'settings')" style="float: right">Settings</div>
					</div>

					<div class="panel w3-margin-left w3-margin-top w3-padding" style="width: 900px; height: auto; float: left">
						<template class="headerRowTaskTemplate">
							<tr>
								<th class="category" style="width: 250px">Job</th>
								<th>Level</th>
								<th class="valueType">Value type</th>
								<th>Xp/day</th>
								<th>Xp left</th>
								<th class="maxLevel">Max level</th>
								<th class="skipSkill">Skip</th>
							</tr>
						</template>

						<template class="headerRowItemTemplate">
							<tr>
								<th class="category" style="width: 250px">Item</th>
								<th style="width: 100px">Active</th>
								<th style="width: 250px">Effect</th>
								<th>Expense/day</th>
							</tr>
						</template>

						<template class="rowTaskTemplate">
							<tr>
								<td>
									<div class="progress-bar progressBar tooltip">
										<div class="progress-fill progressFill"></div>
										<div class="progress-text name">Task</div>
										<span class="tooltipText"></span>
									</div>
								</td>
								<td class="level">Level</td>
								<td class="value">
									<div class="effect"></div>
									<div class="income">
										<span></span>
										<span></span>
										<span></span>
										<span></span>
									</div>
								</td>
								<td class="xpGain">Xp/day</td>
								<td class="xpLeft">Xp left</td>
								<td class="maxLevel">Max level</td>
								<td class="skipSkill">
									<input class="checkbox" type="checkbox"></input>
								</td>
							</tr>
						</template>

						<template class="rowItemTemplate">
							<tr>
								<td>

									<button class="button item-button tooltip">
										<span class="name"></span>
										<span class="tooltipText">tooltip</span>
									</button>

								</td>
								<td>
									<div class="w3-border w3-circle" style="width: 40px; height: 40px; padding: 7px">
										<div class="active w3-circle" style="width: 24px; height: 24px;"></div>
									</div>
								</td>
								<td class="effect"></td>
								<td class="expense">
									<span></span>
									<span></span>
									<span></span>
									<span></span>
								</td>
							</tr>
						</template>

						<template class="requiredRowTemplate">
							<td class="w3-text-gray" style="padding-left: 16px" colspan=5>
								Required:
								<span class="value" colspan=5>
									<span class="levels"></span>
									<span class="coins">
										<span></span>
										<span></span>
										<span></span>
										<span></span>
									</span>
									<span style="color: rgb(200, 0, 0)" class="evil"></span>
								</span>
							</td>
						</template>

						<div class="tab" id="jobs">
							<table id="jobTable" class="w3-table w3-bordered">
							</table>
						</div>

						<div class="tab" id="skills">
							<table id="skillTable" class="w3-table w3-bordered">
							</table>
						</div>

						<div class="tab" id="shop">
							<table id="itemTable" class="w3-table w3-bordered">
							</table>
						</div>

						<div class="tab" id="rebirth">
							<ul>
								<li style="margin: 8px;">
									Digging through a battered box of loaner gear at an event, you find a strange old medallion —
									a dead knight's persona token, by the look of it. It's cheap pot-metal and nobody claims it,
									so you pocket it. Something about it just feels like it wants to come home with you.
								</li>
								<li style="margin: 8px;" id="rebirthNote1">
									A few seasons later the medallion goes cold and heavy in your pouch one night at fighter
									practice. When you pull it out, an old maker's mark you swear wasn't there before is etched
									across its face.
								</li>
								<li style="margin: 8px;" id="rebirthNote2">
									<div style="margin-bottom: 8px">
										After years on the field your knees are shot and your sword arm isn't what it was. That's when
										the relic stirs again — and you realize it's offering you a clean slate. A brand new persona,
										a brand new name at gate, fresh out of the loaner pile.
									</div>
									<i style="color: grey">
										Roll a new persona and you start over from scratch, losing all your levels and coins.
										But your hands remember. You keep <b>xp multipliers</b> on every class and skill equal to:
										<b>1 + the max level of that class or skill / 10.</b>
										Everything you grind the second time comes much faster than it did the first.
										<span style="color: rgb(200, 0, 0)">
										And you get the feeling the relic has darker things to offer if you stick around long enough...</span>
									</i>
									</br>
									<button class="w3-button button" style="margin-bottom: 8px; margin-top: 8px" onClick="rebirthOne()">Reroll Persona</button>
									</br>
								</li>
								<li style="margin: 8px;" id="rebirthNote3">
									<div style="margin-bottom: 8px;">
										You were right to be wary. After enough lifetimes on the field, the relic finally speaks —
										a low whisper offering you the path the knights never talk about around the fire:
										forsake your oaths, and the dead will fight for you.
									</div>
									<i style="color: rgb(200, 0, 0)">
										Take the Anti-Paladin's Oath and everything resets — every level, every coin, even your max levels.
										You begin again as a blank slate. But you unlock the dark line of skills and gain
										<b><span id="evilGainDisplay"></span> Corruption</b>, which will haunt every life that follows.
									</i>
									</br>
										<button class="w3-button button" style="margin-bottom: 8px; margin-top: 8px" onClick="rebirthTwo()">Take the Oath</button>
									</br>
								</li>
							</ul>
						</div>

						<div class="tab" id="settings">
							<ul>
								<li>
									<h2>Import/export save</h2>
									<button class="w3-button button" onClick="importGameData()">Import</button>
									<button class="w3-button button" onClick="exportGameData()">Export</button>
									<form style="margin-top: 16px">
										<input id="importExportBox" type="text" style="width:300px; height:30px"></input>
									</form>
								</li>
								<li>
									<h2>Hard reset game</h2>
									<button class="w3-button button w3-red" onClick="resetGameData()">Reset</button>
								</li>
							</ul>
						</div>
					</div>
				</div>
			</div>
		</div>

	</div>
</div>

<script type="text/javascript" src="<?=HTTP_TEMPLATE?>default/orkremental/classes.js?v=<?=filemtime(__DIR__.'/orkremental/classes.js')?>"></script>
<script type="text/javascript" src="<?=HTTP_TEMPLATE?>default/orkremental/game.js?v=<?=filemtime(__DIR__.'/orkremental/game.js')?>"></script>
