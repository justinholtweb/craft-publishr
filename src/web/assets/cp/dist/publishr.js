/**
 * Publishr — control panel behaviour.
 *
 * A plain IIFE with no build step and no dependencies beyond Craft's own `Craft.sendActionRequest`.
 * Everything here is progressive: with JavaScript off, every screen still renders and every form
 * still posts, because each action is also a real endpoint reachable from a normal form.
 */
(function () {
  'use strict';

  var CSRF = function () {
    return { name: window.Craft && Craft.csrfTokenName, value: window.Craft && Craft.csrfTokenValue };
  };

  function post(action, data) {
    return Craft.sendActionRequest('POST', action, { data: data });
  }

  function notify(response, fallback) {
    var message = (response && response.data && response.data.message) || fallback;
    if (message) { Craft.cp.displayNotice(message); }
  }

  function fail(error, fallback) {
    var message =
      (error && error.response && error.response.data && error.response.data.message) || fallback;
    Craft.cp.displayError(message);
  }

  // ---------------------------------------------------------------- drag and drop

  /**
   * Dragging a pip to another day.
   *
   * The lane travels with the drag, because which date this writes depends entirely on it: the
   * same piece dragged in the "due" lane must move its deadline and in the "publish" lane must
   * move its post date. A handler that guessed would be wrong about half the time.
   *
   * HTML5 drag and drop rather than a pointer-events implementation: it is what the platform
   * gives us for free, it announces itself to assistive technology, and a calendar cell is a
   * generous target that does not need sub-pixel precision.
   */
  function initCalendarDrag(root) {
    var dragged = null;

    root.addEventListener('dragstart', function (event) {
      var pip = event.target.closest('[data-pub-pip]');
      if (!pip) { return; }
      dragged = pip;
      event.dataTransfer.effectAllowed = 'move';
      // Firefox refuses to start a drag at all unless something is written to the transfer.
      event.dataTransfer.setData('text/plain', pip.getAttribute('data-element-id') || '');
    });

    root.addEventListener('dragend', function () {
      dragged = null;
      Array.prototype.forEach.call(root.querySelectorAll('.is-dropping'), function (cell) {
        cell.classList.remove('is-dropping');
      });
    });

    root.addEventListener('dragover', function (event) {
      var cell = event.target.closest('[data-pub-day]');
      if (!cell || !dragged) { return; }
      event.preventDefault();
      cell.classList.add('is-dropping');
    });

    root.addEventListener('dragleave', function (event) {
      var cell = event.target.closest('[data-pub-day]');
      if (cell) { cell.classList.remove('is-dropping'); }
    });

    root.addEventListener('drop', function (event) {
      var cell = event.target.closest('[data-pub-day]');
      if (!cell || !dragged) { return; }
      event.preventDefault();
      cell.classList.remove('is-dropping');

      var date = cell.getAttribute('data-pub-day');
      var pip = dragged;

      if (pip.getAttribute('data-date') === date) { return; }

      // Moved optimistically, then put back if the server refuses. A calendar that waits for a
      // round trip before the card moves feels broken on any connection worse than an office one.
      var previousParent = pip.parentNode;
      var previousDate = pip.getAttribute('data-date');
      var target = cell.querySelector('[data-pub-day-pips]') || cell;
      target.appendChild(pip);
      pip.setAttribute('data-date', date);

      post('publishr/calendar/move', {
        elementId: pip.getAttribute('data-element-id'),
        siteId: pip.getAttribute('data-site-id'),
        lane: pip.getAttribute('data-lane'),
        date: date
      })
        .then(function (response) { notify(response, ''); })
        .catch(function (error) {
          previousParent.appendChild(pip);
          pip.setAttribute('data-date', previousDate);
          fail(error, Craft.t('publishr', 'Couldn’t move that.'));
        });
    });
  }

  // ------------------------------------------------------------------- the panel

  function initPanel(panel) {
    var elementId = panel.getAttribute('data-element-id');
    var siteId = panel.getAttribute('data-site-id');

    function base(extra) {
      var data = { elementId: elementId, siteId: siteId };
      for (var key in extra) { if (Object.prototype.hasOwnProperty.call(extra, key)) { data[key] = extra[key]; } }
      return data;
    }

    panel.addEventListener('change', function (event) {
      var stage = event.target.closest('[data-pub-stage-select]');
      if (stage) {
        post('publishr/items/stage', base({ stageId: stage.value }))
          .then(function (response) { notify(response, ''); })
          .catch(function (error) {
            // Put the select back: leaving it showing a stage the piece is not on is a lie the
            // editor will act on.
            stage.value = stage.getAttribute('data-current') || '';
            fail(error, Craft.t('publishr', 'Couldn’t move that.'));
          });
        stage.setAttribute('data-current', stage.value);
        return;
      }

      var assignee = event.target.closest('[data-pub-assignee]');
      if (assignee) {
        post('publishr/items/assign', base({ assigneeId: assignee.value }))
          .then(function (response) { notify(response, ''); })
          .catch(function (error) { fail(error, Craft.t('publishr', 'Couldn’t assign that.')); });
        return;
      }

      var tick = event.target.closest('[data-pub-tick]');
      if (tick) {
        var gate = tick.getAttribute('data-gate');
        var boxes = panel.querySelectorAll('[data-pub-tick][data-gate="' + gate + '"]');
        var ticked = [];
        Array.prototype.forEach.call(boxes, function (box) {
          if (box.checked) { ticked.push(box.value); }
        });
        post('publishr/items/tick', base({ gate: gate, ticked: ticked }))
          .catch(function (error) { fail(error, Craft.t('publishr', 'Couldn’t save that.')); });
      }
    });

    panel.addEventListener('click', function (event) {
      var button = event.target.closest('[data-pub-action]');
      if (!button) { return; }
      event.preventDefault();

      var action = button.getAttribute('data-pub-action');
      var payload = base({});

      if (action === 'publishr/items/due') {
        var input = panel.querySelector('[data-pub-due]');
        payload.dueDate = input ? input.value : '';
      }

      if (action === 'publishr/comments/save') {
        var body = panel.querySelector('[data-pub-comment-body]');
        if (!body || !body.value.trim()) { return; }
        payload.body = body.value;
      }

      if (action === 'publishr/comments/resolve' || action === 'publishr/comments/delete') {
        payload.id = button.getAttribute('data-id');
        payload.resolved = button.getAttribute('data-resolved') || '1';
      }

      button.classList.add('loading');

      post(action, payload)
        .then(function (response) {
          notify(response, '');
          // The panel is rendered server-side inside Craft's element editor; re-reading it from
          // the DOM after a comment or a sign-off would mean duplicating every template in JS.
          // A reload is honest and costs one request.
          if (button.getAttribute('data-pub-reload') !== 'no') { window.location.reload(); }
        })
        .catch(function (error) { fail(error, Craft.t('publishr', 'That didn’t work.')); })
        .then(function () { button.classList.remove('loading'); });
    });
  }

  // ---------------------------------------------------------------------- bulk

  function initBulk(form) {
    form.addEventListener('submit', function (event) {
      var checked = form.querySelectorAll('[data-pub-select]:checked');
      if (!checked.length) {
        event.preventDefault();
        Craft.cp.displayError(Craft.t('publishr', 'Nothing selected.'));
      }
    });

    var all = form.querySelector('[data-pub-select-all]');
    if (all) {
      all.addEventListener('change', function () {
        Array.prototype.forEach.call(form.querySelectorAll('[data-pub-select]'), function (box) {
          box.checked = all.checked;
        });
      });
    }
  }

  document.addEventListener('DOMContentLoaded', function () {
    var calendar = document.querySelector('[data-pub-calendar]');
    if (calendar) { initCalendarDrag(calendar); }

    Array.prototype.forEach.call(document.querySelectorAll('[data-pub-panel]'), initPanel);

    var bulk = document.querySelector('[data-pub-bulk]');
    if (bulk) { initBulk(bulk); }
  });

  // Craft's element editor renders the sidebar after DOMContentLoaded on a slideout, so the panel
  // has to be picked up again when it appears. Delegated rather than observed: one listener beats
  // a MutationObserver that fires on every keystroke in the editor.
  document.addEventListener('focusin', function (event) {
    var panel = event.target.closest('[data-pub-panel]');
    if (panel && !panel.hasAttribute('data-pub-ready')) {
      panel.setAttribute('data-pub-ready', '1');
      initPanel(panel);
    }
  });
})();
