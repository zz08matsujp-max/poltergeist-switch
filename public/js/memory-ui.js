/*
 * memory-ui.js — small shared helper for the memory additions to the screens
 * (robot-memory spec, Requirement 7).
 *
 * It is shared by the Observe screen (status line + All reset, task 11.1) and
 * the Observe End_Card (reflect-and-show, task 12.1), so the status-line
 * wording, the reflect-result phrasing, and the in-page confirm panel are each
 * written once and used in both places.
 *
 * Everything here is pure DOM/string work: it opens no API itself and keeps no
 * state. The caller passes the status/reflect payloads (from api.memoryStatus /
 * api.reflect) and the confirm callbacks. The two robots are named only Amber
 * and Jade; no AI company or model name ever appears in any text this file
 * produces (Requirement 7.7).
 *
 * No build step on the server: plain browser JS, attached to the window global
 * and exported via CommonJS so a headless check can require it.
 */
(function () {
  'use strict';

  // The status-line text (Requirement 7.1). The format is EXACT:
  //   "Generation N · Amber remembers k · Jade remembers k"
  // using U+00B7 MIDDLE DOT as the separator. `status` is the GET /api/memory
  // body { generation, robots: { amber: {activeCount}, jade: {activeCount} } };
  // a missing field reads as 0 / generation 1 so a partial payload never throws.
  function statusLineText(status) {
    status = status || {};
    var robots = status.robots || {};
    var amber = robots.amber || {};
    var jade = robots.jade || {};
    var generation = numOr(status.generation, 1);
    var amberCount = numOr(amber.activeCount, 0);
    var jadeCount = numOr(jade.activeCount, 0);
    return 'Generation ' + generation +
      ' \u00b7 Amber remembers ' + amberCount +
      ' \u00b7 Jade remembers ' + jadeCount;
  }

  // Render the status line into `host`, replacing any previous content. The
  // host keeps a single line of text; callers style it in screens.css.
  function renderStatusLine(host, status) {
    if (!host) { return; }
    host.textContent = statusLineText(status);
  }

  // Build the in-page All-reset confirmation panel (Requirement 7.2): a small
  // panel with a question and Confirm / Cancel buttons — NO browser alert or
  // confirm dialog. `onConfirm` runs when Confirm is pressed; `onCancel` (when
  // given) runs when Cancel is pressed. The caller decides where to mount the
  // returned element and when to remove it (both buttons are expected to tear
  // the panel down through the callbacks). Returns the panel element.
  function buildConfirmPanel(onConfirm, onCancel) {
    var panel = document.createElement('div');
    panel.className = 'confirm-panel';
    panel.setAttribute('role', 'dialog');
    panel.setAttribute('aria-label', 'Reset all memory');

    var q = document.createElement('p');
    q.className = 'confirm-question';
    // A plain statement of what Confirm does; it starts a new generation and
    // deletes nothing, so the wording does not claim anything is removed.
    q.textContent = 'Start a new memory generation? The robots will begin with ' +
      'no memories. Nothing already recorded is deleted.';
    panel.appendChild(q);

    var row = document.createElement('div');
    row.className = 'confirm-actions';

    var confirm = document.createElement('button');
    confirm.type = 'button';
    confirm.className = 'btn primary';
    confirm.textContent = 'Confirm';
    confirm.addEventListener('click', function () {
      if (typeof onConfirm === 'function') { onConfirm(); }
    });
    row.appendChild(confirm);

    var cancel = document.createElement('button');
    cancel.type = 'button';
    cancel.className = 'btn';
    cancel.textContent = 'Cancel';
    cancel.addEventListener('click', function () {
      if (typeof onCancel === 'function') { onCancel(); }
    });
    row.appendChild(cancel);

    panel.appendChild(row);
    return panel;
  }

  // Phrase one robot's reflect result for the End_Card (Requirement 7.4, 7.5).
  // `name` is 'Amber' or 'Jade'; `robot` is one of the per-robot objects in the
  // reflect body ({ answered, memory, activeCount, reason }). Returns a plain
  // object { answered, name, memory, activeCount, message } the End_Card (task
  // 12.1) renders — the memory inside a collapsible with its count when
  // answered, else the distinct empty-vs-failed message. No provider name ever
  // appears in `message` (Requirement 7.7).
  function describeReflection(name, robot) {
    robot = robot || {};
    var answered = robot.answered === true;
    if (answered) {
      return {
        answered: true,
        name: name,
        memory: (typeof robot.memory === 'string') ? robot.memory : '',
        activeCount: numOr(robot.activeCount, 0),
        message: ''
      };
    }
    // Not answered: distinguish the empty-answer case (a normal outcome, the
    // robot chose to keep nothing) from a failed/abandoned call (no_reply /
    // timeout). The reason codes come from the reflect response, never shown
    // raw and never naming a provider.
    var reason = robot.reason;
    var message;
    if (reason === 'empty_answer') {
      message = name + ' chose not to write anything down.';
    } else {
      message = name + '\u2019s reflection did not come back.';
    }
    return {
      answered: false,
      name: name,
      memory: '',
      activeCount: numOr(robot.activeCount, 0),
      message: message
    };
  }

  function numOr(v, fallback) {
    return (typeof v === 'number' && isFinite(v)) ? v : fallback;
  }

  var memoryUi = {
    statusLineText: statusLineText,
    renderStatusLine: renderStatusLine,
    buildConfirmPanel: buildConfirmPanel,
    describeReflection: describeReflection
  };

  if (typeof window !== 'undefined') {
    window.memoryUi = memoryUi;
  }
  if (typeof module !== 'undefined' && module.exports) {
    module.exports = memoryUi;
  }
})();
