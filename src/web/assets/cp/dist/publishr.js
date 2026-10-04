/**
 * Publishr — control panel behaviour.
 *
 * A plain IIFE with no build step and no dependencies beyond Craft's own `Craft.sendActionRequest`
 * and Garnish. Everything here is progressive: with JavaScript off, every screen still renders and
 * every form still posts, because each action is also a real endpoint reachable from a normal form.
 */
(function () {
  'use strict';

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

  // ---------------------------------------------------------------- the calendar

  /**
   * Moving a pip to another day, by dragging it or from the keyboard.
   *
   * The lane travels with the move, because which date this writes depends entirely on it: the
   * same piece moved in the "due" lane must move its deadline and in the "publish" lane must move
   * its post date. A handler that guessed would be wrong about half the time.
   *
   * Moved optimistically, then put back if the server refuses. A calendar that waits for a round
   * trip before the card moves feels broken on any connection worse than an office one.
   */
  function movePip(root, pip, date) {
    if (!date || pip.getAttribute('data-date') === date) { return; }

    var previousParent = pip.parentNode;
    var previousNext = pip.nextSibling;
    var previousDate = pip.getAttribute('data-date');
    var cell = root.querySelector('[data-pub-day="' + date + '"]');

    // A date outside the month on screen has nowhere to be drawn; the pip leaves until it's back.
    if (cell) {
      (cell.querySelector('[data-pub-day-pips]') || cell).appendChild(pip);
    } else {
      pip.hidden = true;
    }
    pip.setAttribute('data-date', date);

    post('publishr/calendar/move', {
      elementId: pip.getAttribute('data-element-id'),
      siteId: pip.getAttribute('data-site-id'),
      lane: pip.getAttribute('data-lane'),
      date: date
    })
      .then(function (response) { notify(response, ''); })
      .catch(function (error) {
        previousParent.insertBefore(pip, previousNext);
        pip.hidden = false;
        pip.setAttribute('data-date', previousDate);
        fail(error, Craft.t('publishr', 'Couldn’t move that.'));
      });
  }

  /**
   * Drag and drop is a mouse-only affordance, so every pip the viewer may move can also be moved
   * from the keyboard: focus it and press M for a date picker in a HUD.
   */
  function openMoveHud(root, pip) {
    var id = 'pub-move-' + Math.random().toString(36).slice(2);
    var $body = $('<div class="flex"/>');
    var $label = $('<label/>', { 'for': id, text: Craft.t('publishr', 'Move to') }).appendTo($body);
    var $input = $('<input/>', { id: id, type: 'date', 'class': 'text', value: pip.getAttribute('data-date') }).appendTo($body);
    $('<button/>', { type: 'submit', 'class': 'btn submit', text: Craft.t('publishr', 'Move') }).appendTo($body);

    var hud = new Garnish.HUD(pip, $body, {
      hideOnEsc: true,
      onSubmit: function () {
        var date = $input.val();
        hud.hide();
        movePip(root, pip, date);
        pip.focus();
      }
    });

    hud.on('show', function () { $input.trigger('focus'); });
    $label.addClass('visually-hidden');
    setTimeout(function () { $input.trigger('focus'); }, 0);
  }

  function initCalendar(root) {
    var dragged = null;

    root.addEventListener('dragstart', function (event) {
      var pip = event.target.closest('[data-pub-pip][draggable="true"]');
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
      movePip(root, dragged, cell.getAttribute('data-pub-day'));
    });

    root.addEventListener('keydown', function (event) {
      if (event.key !== 'm' && event.key !== 'M') { return; }
      if (event.altKey || event.ctrlKey || event.metaKey) { return; }
      var pip = event.target.closest('[data-pub-pip][draggable="true"]');
      if (!pip) { return; }
      event.preventDefault();
      openMoveHud(root, pip);
    });
  }

  // ------------------------------------------------------------------- the panel

  /**
   * The entry-editor panel.
   *
   * Listeners are delegated from the document rather than bound per panel. The panel arrives in
   * a slideout long after this script has run, possibly more than once on the same page, and a
   * per-panel set-up has to be told about each one — and told only once, or every action posts
   * twice. Delegation has neither problem.
   */
  function panelData(panel, extra) {
    var data = {
      elementId: panel.getAttribute('data-element-id'),
      siteId: panel.getAttribute('data-site-id')
    };
    for (var key in extra) { if (Object.prototype.hasOwnProperty.call(extra, key)) { data[key] = extra[key]; } }
    return data;
  }

  // Inside a slideout the page underneath is not the entry, so reloading it would throw the
  // slideout away. Full-page editors reload; slideouts say what happened and leave it there.
  function inSlideout(panel) {
    return !!panel.closest('.slideout-container, .slideout');
  }

  function busy(button, on) {
    button.classList.toggle('loading', on);
    button.disabled = on;
    button.setAttribute('aria-busy', on ? 'true' : 'false');
  }

  document.addEventListener('change', function (event) {
    var panel = event.target.closest('[data-pub-panel]');
    if (!panel) { return; }

    var stage = event.target.closest('[data-pub-stage-select]');
    if (stage) {
      var previous = stage.getAttribute('data-current') || '';
      stage.setAttribute('data-current', stage.value);
      post('publishr/items/stage', panelData(panel, { stageId: stage.value }))
        .then(function (response) { notify(response, ''); })
        .catch(function (error) {
          // Put the select back: leaving it showing a stage the piece is not on is a lie the
          // editor will act on.
          stage.value = previous;
          stage.setAttribute('data-current', previous);
          fail(error, Craft.t('publishr', 'Couldn’t move that.'));
        });
      return;
    }

    var assignee = event.target.closest('[data-pub-assignee]');
    if (assignee) {
      post('publishr/items/assign', panelData(panel, { assigneeId: assignee.value }))
        .then(function (response) { notify(response, ''); })
        .catch(function (error) { fail(error, Craft.t('publishr', 'Couldn’t assign that.')); });
      return;
    }

    var tick = event.target.closest('[data-pub-tick]');
    if (tick) {
      var gate = tick.getAttribute('data-gate');
      var ticked = [];
      Array.prototype.forEach.call(panel.querySelectorAll('[data-pub-tick]'), function (box) {
        if (box.getAttribute('data-gate') === gate && box.checked) { ticked.push(box.value); }
      });
      post('publishr/items/tick', panelData(panel, { gate: gate, ticked: ticked }))
        .catch(function (error) { fail(error, Craft.t('publishr', 'Couldn’t save that.')); });
    }
  });

  // Enter in the deadline box would otherwise submit Craft's entry form around the panel and
  // save the entry, which is not what anybody pressing Enter in a date field means.
  document.addEventListener('keydown', function (event) {
    if (event.key !== 'Enter') { return; }
    var due = event.target.closest('[data-pub-panel] [data-pub-due]');
    if (!due) { return; }
    event.preventDefault();
    event.stopPropagation();
    var button = due.closest('[data-pub-panel]').querySelector('[data-pub-action="publishr/items/due"]');
    if (button) { button.click(); }
  }, true);

  document.addEventListener('click', function (event) {
    var button = event.target.closest('[data-pub-panel] [data-pub-action]');
    if (!button || button.disabled) { return; }
    event.preventDefault();

    var panel = button.closest('[data-pub-panel]');
    var action = button.getAttribute('data-pub-action');
    var payload = panelData(panel, {});

    if (action === 'publishr/items/due') {
      var input = panel.querySelector('[data-pub-due]');
      payload.dueDate = input ? input.value : '';
    }

    if (action === 'publishr/comments/save') {
      var body = panel.querySelector('[data-pub-comment-body]');
      if (!body || !body.value.trim()) { return; }
      payload.body = body.value;
    }

    if (action === 'publishr/comments/resolve') {
      payload.id = button.getAttribute('data-id');
      payload.resolved = button.getAttribute('data-resolved') || '1';
    }

    busy(button, true);

    post(action, payload)
      .then(function (response) {
        if (button.getAttribute('data-pub-reload') === 'no') {
          notify(response, '');
          return;
        }
        // The panel is rendered server-side inside Craft's element editor; re-drawing it from
        // the response would mean duplicating every template in JS. On a full-page editor a
        // reload is honest and costs one request.
        if (inSlideout(panel)) {
          if (action === 'publishr/comments/save') {
            panel.querySelector('[data-pub-comment-body]').value = '';
          }
          Craft.cp.displayNotice(Craft.t('publishr', 'Saved. Reload the page to see the panel’s new state.'));
          return;
        }
        notify(response, '');
        window.location.reload();
      })
      .catch(function (error) { fail(error, Craft.t('publishr', 'That didn’t work.')); })
      .then(function () { busy(button, false); });
  });

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

  // Filter selects that apply as soon as they change. With JavaScript off, each form shows a
  // Filter button instead, so this is a shortcut, not the only way to apply a filter.
  document.addEventListener('change', function (event) {
    var select = event.target.closest('[data-pub-autosubmit]');
    if (select && select.form) { select.form.submit(); }
  });

  function init() {
    var calendar = document.querySelector('[data-pub-calendar]');
    if (calendar && !calendar.hasAttribute('data-pub-ready')) {
      calendar.setAttribute('data-pub-ready', '1');
      initCalendar(calendar);
    }

    var bulk = document.querySelector('[data-pub-bulk]');
    if (bulk && !bulk.hasAttribute('data-pub-ready')) {
      bulk.setAttribute('data-pub-ready', '1');
      initBulk(bulk);
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
