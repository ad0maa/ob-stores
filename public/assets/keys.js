/*
 * Keyboard flow for the dense tables, on every screen: j / k move between
 * rows (as do ↓ / ↑ once a row has focus), Enter opens the row's first link.
 * Plain JavaScript: it doesn't care which generation rendered the table.
 */
(function () {
  'use strict';

  var ROW = 'table.grid tbody tr:not(.group)';

  function visibleRows() {
    return Array.prototype.filter.call(document.querySelectorAll(ROW), function (row) {
      return row.offsetParent !== null;
    });
  }

  function isTyping(el) {
    return el.closest('input, select, textarea, button, [contenteditable]') !== null;
  }

  document.addEventListener('keydown', function (event) {
    if (event.altKey || event.ctrlKey || event.metaKey || isTyping(event.target)) return;

    var focusedRow = event.target.closest(ROW);
    var down = event.key === 'j' || (focusedRow && event.key === 'ArrowDown');
    var up = event.key === 'k' || (focusedRow && event.key === 'ArrowUp');

    if (down || up) {
      var rows = visibleRows();
      if (rows.length === 0) return;
      var index = rows.indexOf(focusedRow);
      var next = rows[index === -1 ? 0 : Math.max(0, Math.min(rows.length - 1, index + (down ? 1 : -1)))];
      next.tabIndex = -1;
      next.focus();
      next.scrollIntoView({ block: 'nearest' });
      event.preventDefault();
    } else if (event.key === 'Enter' && focusedRow === event.target) {
      var link = focusedRow.querySelector('a[href]');
      if (link) {
        link.click();
        event.preventDefault();
      }
    }
  });
})();
