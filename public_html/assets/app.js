// Небольшие улучшения интерфейса

// Закрывать мобильное меню при клике на пункт навигации
document.addEventListener('DOMContentLoaded', function () {
    var nav = document.getElementById('mainNav');
    if (nav) {
        nav.querySelectorAll('a').forEach(function (link) {
            link.addEventListener('click', function () {
                nav.classList.remove('open');
            });
        });
    }
});

// ---------------------------------------------------------------------
// Канбан: перенос карточек мышью и пальцем + запасной выпадающий список
// ---------------------------------------------------------------------
document.addEventListener('DOMContentLoaded', function () {
    var board = document.getElementById('kanbanBoard');
    if (!board) { return; }

    var csrf = board.getAttribute('data-csrf') || '';

    function postStatus(taskId, newStatus) {
        var data = new FormData();
        data.append('task_id', taskId);
        data.append('new_status', newStatus);
        data.append('csrf_token', csrf);

        return fetch('task_status_update.php', { method: 'POST', body: data, credentials: 'same-origin' })
            .then(function (r) { return r.json().catch(function () { return { ok: false, error: 'Сервер вернул неожиданный ответ.' }; }); });
    }

    function applyMove(taskId, newStatus) {
        postStatus(taskId, newStatus).then(function (res) {
            if (res && res.ok) {
                window.location.reload();
            } else {
                alert((res && res.error) ? res.error : 'Не удалось изменить статус.');
                window.location.reload();
            }
        }).catch(function () {
            alert('Нет связи с сервером. Проверьте интернет и повторите.');
        });
    }

    // --- Запасной вариант: выбор статуса из списка ---
    board.querySelectorAll('.task-move-select').forEach(function (select) {
        select.addEventListener('change', function () {
            if (!select.value) { return; }
            applyMove(select.getAttribute('data-task-id'), select.value);
        });
        // клик по списку не должен открывать карточку
        select.addEventListener('click', function (ev) { ev.stopPropagation(); });
    });

    // --- Перетаскивание на pointer-событиях (работает и на тач-экранах) ---
    var dragState = null;
    var DRAG_THRESHOLD = 8;

    board.addEventListener('pointerdown', function (ev) {
        if (ev.button !== undefined && ev.button !== 0) { return; }
        if (ev.target.closest('select, option, button')) { return; }

        var card = ev.target.closest('.task-card');
        if (!card) { return; }

        dragState = {
            card: card,
            startX: ev.clientX,
            startY: ev.clientY,
            pointerId: ev.pointerId,
            active: false,
            ghost: null,
            fromStatus: card.closest('.kanban-col').getAttribute('data-status')
        };
    });

    // Слушаем движение на уровне окна: курсор при переносе уходит
    // за пределы доски, и события до неё уже не долетают.
    window.addEventListener('pointermove', function (ev) {
        if (!dragState || ev.pointerId !== dragState.pointerId) { return; }

        var dx = ev.clientX - dragState.startX;
        var dy = ev.clientY - dragState.startY;

        if (!dragState.active) {
            if (Math.sqrt(dx * dx + dy * dy) < DRAG_THRESHOLD) { return; }
            dragState.active = true;

            var rect = dragState.card.getBoundingClientRect();
            var ghost = dragState.card.cloneNode(true);
            ghost.classList.add('task-card-ghost');
            ghost.style.width = rect.width + 'px';
            document.body.appendChild(ghost);

            dragState.ghost = ghost;
            dragState.offsetX = dragState.startX - rect.left;
            dragState.offsetY = dragState.startY - rect.top;
            dragState.card.classList.add('task-card-dragging');

            try { dragState.card.setPointerCapture(ev.pointerId); } catch (e) {}
        }

        ev.preventDefault();
        dragState.ghost.style.left = (ev.clientX - dragState.offsetX) + 'px';
        dragState.ghost.style.top = (ev.clientY - dragState.offsetY) + 'px';

        board.querySelectorAll('.kanban-col-over').forEach(function (c) { c.classList.remove('kanban-col-over'); });
        var target = columnAt(ev.clientX, ev.clientY);
        if (target) { target.classList.add('kanban-col-over'); }
    }, true);

    // Если отпустили точно на колонке — берём её. Если промахнулись
    // (попали в зазор между колонками или ниже короткой колонки) —
    // ищем ближайшую по горизонтали.
    function resolveColumn(x, y) {
        var el = document.elementFromPoint(x, y);
        var direct = el ? el.closest('.kanban-col-droppable') : null;
        if (direct) { return direct; }

        var best = null;
        var bestDistance = Infinity;

        board.querySelectorAll('.kanban-col-droppable').forEach(function (col) {
            var r = col.getBoundingClientRect();
            var distance = 0;

            if (x < r.left) { distance = r.left - x; }
            else if (x > r.right) { distance = x - r.right; }

            // по вертикали допускаем промах, по горизонтали — не больше половины колонки
            if (distance > r.width / 2) { return; }

            if (distance < bestDistance) {
                bestDistance = distance;
                best = col;
            }
        });

        return best;
    }

    function columnAt(x, y) {
        if (dragState && dragState.ghost) { dragState.ghost.style.display = 'none'; }
        var col = resolveColumn(x, y);
        if (dragState && dragState.ghost) { dragState.ghost.style.display = ''; }
        return col;
    }

    function finishDrag(ev) {
        if (!dragState) { return; }

        var state = dragState;
        dragState = null;

        board.querySelectorAll('.kanban-col-over').forEach(function (c) { c.classList.remove('kanban-col-over'); });

        var movedFar = false;
        if (ev) {
            var ddx = ev.clientX - state.startX;
            var ddy = ev.clientY - state.startY;
            movedFar = Math.sqrt(ddx * ddx + ddy * ddy) >= DRAG_THRESHOLD;
        }

        if (!state.active && !movedFar) { return; }

        state.card.classList.remove('task-card-dragging');
        if (state.ghost && state.ghost.parentNode) { state.ghost.parentNode.removeChild(state.ghost); }

        // Подавляем клик, который браузер пошлёт после отпускания
        suppressClickOnce();

        var target = ev ? columnAtSimple(ev.clientX, ev.clientY) : null;
        if (!target) { return; }

        var newStatus = target.getAttribute('data-status');
        if (!newStatus || newStatus === state.fromStatus) { return; }

        applyMove(state.card.getAttribute('data-task-id'), newStatus);
    }

    function columnAtSimple(x, y) {
        return resolveColumn(x, y);
    }

    function suppressClickOnce() {
        var handler = function (ev) {
            ev.preventDefault();
            ev.stopPropagation();
            document.removeEventListener('click', handler, true);
        };
        document.addEventListener('click', handler, true);
        setTimeout(function () { document.removeEventListener('click', handler, true); }, 400);
    }

    // Завершение слушаем на уровне окна: при перетаскивании палец или курсор
    // легко уходит за пределы доски, и pointerup туда уже не долетает.
    window.addEventListener('pointerup', finishDrag, true);
    window.addEventListener('pointercancel', function () {
        if (dragState && dragState.ghost && dragState.ghost.parentNode) {
            dragState.ghost.parentNode.removeChild(dragState.ghost);
        }
        if (dragState && dragState.card) {
            dragState.card.classList.remove('task-card-dragging');
        }
        dragState = null;
    }, true);
});
