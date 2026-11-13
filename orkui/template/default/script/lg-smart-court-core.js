/* Smart Planning Grid: pure client logic (no DOM access). */
var LgSmartCore = (function () {
	'use strict';

	function maxRank(awardName) {
		return /zodiac/i.test(String(awardName || '')) ? 12 : 10;
	}

	function same(r, mundaneId, col) {
		return String(r.mundaneId) === String(mundaneId) && r.col === col;
	}

	function effectiveRank(held, rows, mundaneId, col) {
		var best = Number(held) || 0;
		for (var i = 0; i < (rows || []).length; i++) {
			if (same(rows[i], mundaneId, col) && Number(rows[i].rank) > best) best = Number(rows[i].rank);
		}
		return best;
	}

	function nextRank(held, rows, mundaneId, col) {
		return effectiveRank(held, rows, mundaneId, col) + 1;
	}

	function cellCount(rows, mundaneId, col) {
		var n = 0;
		for (var i = 0; i < (rows || []).length; i++) {
			if (same(rows[i], mundaneId, col)) n++;
		}
		return n;
	}

	function copy(r) {
		var o = {};
		for (var k in r) if (Object.prototype.hasOwnProperty.call(r, k)) o[k] = r[k];
		return o;
	}

	function removeRow(rows, id) {
		var gone = null, i;
		for (i = 0; i < rows.length; i++) {
			if (rows[i].id === id) { gone = rows[i]; break; }
		}
		if (!gone) return rows.slice();
		var out = [];
		for (i = 0; i < rows.length; i++) {
			var r = rows[i];
			if (r === gone) continue;
			if (!gone.saved && !r.saved && same(r, gone.mundaneId, gone.col) && Number(r.rank) > Number(gone.rank)) {
				r = copy(r);
				r.rank = Number(r.rank) - 1;
			}
			out.push(r);
		}
		return out;
	}

	function assignRecs(rows, recs) {
		var claimed = {}, i, j;
		recs = recs || [];
		for (i = 0; i < rows.length; i++) {
			if (rows[i].saved && rows[i].recId != null) claimed[String(rows[i].recId)] = true;
		}
		var out = [];
		for (i = 0; i < rows.length; i++) {
			var r = copy(rows[i]);
			if (!r.saved) {
				r.recId = null;
				for (j = 0; j < recs.length; j++) {
					var c = recs[j];
					if (claimed[String(c.id)]) continue;
					if (String(c.mundaneId) === String(r.mundaneId) && c.col === r.col && Number(c.rank) === Number(r.rank)) {
						r.recId = c.id;
						claimed[String(c.id)] = true;
						break;
					}
				}
			}
			out.push(r);
		}
		return out;
	}

	function recsAbove(recs, mundaneId, col, held) {
		var out = [];
		for (var i = 0; i < (recs || []).length; i++) {
			var c = recs[i];
			if (String(c.mundaneId) === String(mundaneId) && c.col === col && Number(c.rank) > Number(held)) out.push(c);
		}
		return out;
	}

	function isOverLimit(rank, limit) {
		return limit > 0 && rank > limit;
	}

	function isAtLimit(effective, limit) {
		return limit > 0 && effective >= limit;
	}

	function dayNum(s) {
		var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(s || ''));
		if (!m) return null;
		return Date.UTC(+m[1], +m[2] - 1, +m[3]) / 86400000;
	}

	function daysBetween(dateStr, todayStr) {
		var a = dayNum(dateStr), b = dayNum(todayStr);
		if (a === null || b === null) return null;
		return b - a;
	}

	function ageFraction(dateStr, todayStr) {
		var d = daysBetween(dateStr, todayStr);
		if (d === null) return null;
		return Math.max(0, Math.min(1, d / 365));
	}

	function isOverdue(lastSignIn, lastAward, todayStr) {
		var s = daysBetween(lastSignIn, todayStr), a = daysBetween(lastAward, todayStr);
		if (s === null || a === null) return false;
		return s <= 60 && a > 180;
	}

	function findDuplicates(rows, courtLines, colOf) {
		var live = {}, i;
		for (i = 0; i < (courtLines || []).length; i++) {
			var l = courtLines[i];
			var col = colOf(l.KingdomAwardId);
			if (col == null || col === '') continue;
			live[l.MundaneId + '|' + col + '|' + Number(l.Rank)] = true;
		}
		var out = [];
		for (i = 0; i < (rows || []).length; i++) {
			var r = rows[i];
			if (!r.saved && live[r.mundaneId + '|' + r.col + '|' + Number(r.rank)]) out.push(r.id);
		}
		return out;
	}

	function parseStored(json, scopeKey) {
		var o;
		try { o = JSON.parse(json); } catch (e) { return null; }
		if (!o || typeof o !== 'object') return null;
		if (o.v !== 1 || o.scope !== scopeKey) return null;
		if (!o.court || typeof o.court !== 'object') return null;
		if (typeof o.limit !== 'number' || !Array.isArray(o.rows)) return null;
		return o;
	}

	var api = {
		maxRank: maxRank, effectiveRank: effectiveRank, nextRank: nextRank, cellCount: cellCount,
		removeRow: removeRow, assignRecs: assignRecs, recsAbove: recsAbove, isOverLimit: isOverLimit,
		isAtLimit: isAtLimit, ageFraction: ageFraction, isOverdue: isOverdue,
		findDuplicates: findDuplicates, parseStored: parseStored
	};
	if (typeof module !== 'undefined' && module.exports) module.exports = api;
	return api;
})();
