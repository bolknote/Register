(() => {
    'use strict';

    const reactionTypes = ['like', 'love', 'haha', 'wow', 'sad', 'angry'];
    const widgets = new WeakMap();

    class ReactionWidget {
        constructor(root) {
            this.root = root;
            this.endpoint = root.dataset.endpoint || '';
            this.toolbar = root.querySelector('.register-reaction-toolbar');
            this.primaryButton = root.querySelector('.register-reaction-primary');
            this.picker = root.querySelector('.register-reaction-picker');
            this.status = root.querySelector('.register-reaction-status');
            this.chips = new Map();
            this.choices = new Map();
            this.counts = Object.fromEntries(reactionTypes.map((type) => [type, 0]));
            this.selected = null;
            this.busy = false;
            this.stateRevision = 0;
            this.openTimer = 0;
            this.closeTimer = 0;
            this.longPressTimer = 0;
            this.suppressPrimaryClick = false;

            for (const button of root.querySelectorAll('[data-reaction]')) {
                const type = button.dataset.reaction;
                if (reactionTypes.includes(type)) {
                    this.chips.set(type, button);
                    this.counts[type] = Math.max(0, Number.parseInt(button.dataset.count || '0', 10) || 0);
                }
            }
            for (const button of root.querySelectorAll('[data-picker-reaction]')) {
                const type = button.dataset.pickerReaction;
                if (reactionTypes.includes(type)) {
                    this.choices.set(type, button);
                }
            }

            this.bind();
            this.render();
            void this.hydrate();
        }

        bind() {
            for (const [type, button] of this.chips) {
                button.addEventListener('click', (event) => {
                    if (button === this.primaryButton && this.suppressPrimaryClick) {
                        event.preventDefault();
                        this.suppressPrimaryClick = false;
                        return;
                    }
                    void this.select(type);
                });
            }
            for (const [type, button] of this.choices) {
                button.addEventListener('click', () => {
                    this.suppressPrimaryClick = false;
                    void this.select(type);
                });
            }

            const cancelLongPress = () => window.clearTimeout(this.longPressTimer);
            for (const button of this.chips.values()) {
                button.addEventListener('pointerdown', (event) => {
                    if (button !== this.primaryButton || event.pointerType === 'mouse') {
                        return;
                    }
                    cancelLongPress();
                    this.longPressTimer = window.setTimeout(() => {
                        this.suppressPrimaryClick = true;
                        this.openPicker(false);
                    }, 420);
                });
                button.addEventListener('pointerup', cancelLongPress);
                button.addEventListener('pointercancel', cancelLongPress);
                button.addEventListener('pointerleave', cancelLongPress);
            }

            this.root.addEventListener('keydown', (event) => this.onKeyDown(event));
            this.documentPointerHandler = (event) => {
                if (!this.root.contains(event.target)) {
                    this.closePicker(false);
                }
            };
            document.addEventListener('pointerdown', this.documentPointerHandler);

            if (window.matchMedia('(hover: hover)').matches) {
                for (const button of this.chips.values()) {
                    button.addEventListener('pointerenter', () => {
                        if (button !== this.primaryButton) {
                            return;
                        }
                        window.clearTimeout(this.closeTimer);
                        this.openTimer = window.setTimeout(() => this.openPicker(false), 220);
                    });
                }
                this.root.addEventListener('pointerleave', () => {
                    window.clearTimeout(this.openTimer);
                    this.closeTimer = window.setTimeout(() => this.closePicker(false), 450);
                });
                this.root.addEventListener('pointerenter', () => window.clearTimeout(this.closeTimer));
            }
        }

        async hydrate() {
            const stateRevision = this.stateRevision;
            try {
                await this.identity().ensure();
                const response = await fetch(this.endpoint, {
                    credentials: 'same-origin',
                    headers: {'Accept': 'application/json'},
                });
                const payload = await response.json();
                if (response.ok && payload.success === true && stateRevision === this.stateRevision) {
                    this.applyPayload(payload);
                }
            } catch (_error) {
                // Server-rendered counts remain usable if hydration is unavailable.
            }
        }

        identity() {
            const identity = window.RegisterVisitorIdentity;
            if (!identity || typeof identity.ensure !== 'function') {
                throw new Error('Visitor identity is unavailable.');
            }
            return identity;
        }

        async select(type) {
            if (this.busy || !reactionTypes.includes(type)) {
                return;
            }

            this.closePicker(false);
            this.stateRevision += 1;
            const snapshot = {
                counts: {...this.counts},
                selected: this.selected,
            };
            this.optimisticToggle(type);
            this.setBusy(true);

            try {
                await this.identity().ensure();
                let response = await this.post(type);
                if (response.status === 401 && typeof this.identity().refresh === 'function') {
                    await this.identity().refresh();
                    response = await this.post(type);
                }

                const payload = await response.json().catch(() => null);
                if (!response.ok || !payload || payload.success !== true) {
                    throw new Error(payload?.message || 'Unable to save the reaction.');
                }

                this.applyPayload(payload);
                this.setStatus(this.root.dataset.messageSaved || 'Reaction saved.', false);
            } catch (_error) {
                this.counts = snapshot.counts;
                this.selected = snapshot.selected;
                this.render();
                this.setStatus(this.root.dataset.messageError || 'Unable to save the reaction.', true);
            } finally {
                this.setBusy(false);
            }
        }

        post(type) {
            return fetch(this.endpoint, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({reaction: type}),
            });
        }

        optimisticToggle(type) {
            if (this.selected === type) {
                this.counts[type] = Math.max(0, this.counts[type] - 1);
                this.selected = null;
            } else {
                if (this.selected !== null) {
                    this.counts[this.selected] = Math.max(0, this.counts[this.selected] - 1);
                }
                this.counts[type] += 1;
                this.selected = type;
            }
            this.render();
        }

        applyPayload(payload) {
            for (const type of reactionTypes) {
                const count = Number(payload.counts?.[type]);
                this.counts[type] = Number.isSafeInteger(count) && count >= 0 ? count : 0;
            }
            this.selected = reactionTypes.includes(payload.selected) ? payload.selected : null;
            this.render();
        }

        render() {
            this.setPrimaryButton(this.chips.get(this.primaryType()) || null);

            for (const [type, button] of this.chips) {
                const count = this.counts[type];
                const active = this.selected === type;
                const countNode = button.querySelector('.register-reaction-count');
                button.hidden = button !== this.primaryButton && count === 0 && !active;
                button.classList.toggle('is-visible', !button.hidden);
                button.setAttribute('aria-pressed', String(active));
                button.dataset.count = String(count);
                if (countNode) {
                    countNode.textContent = String(count);
                    countNode.hidden = count === 0;
                }
            }

            for (const [type, button] of this.choices) {
                button.setAttribute('aria-checked', String(this.selected === type));
            }
        }

        primaryType() {
            if (this.selected !== null) {
                return this.selected;
            }

            let primary = 'like';
            let maxCount = 0;
            for (const type of reactionTypes) {
                if (this.counts[type] > maxCount) {
                    primary = type;
                    maxCount = this.counts[type];
                }
            }

            return primary;
        }

        setPrimaryButton(button) {
            if (!(button instanceof HTMLButtonElement)) {
                return;
            }

            const expanded = this.picker?.hidden === false;
            for (const chip of this.chips.values()) {
                const primary = chip === button;
                chip.classList.toggle('register-reaction-primary', primary);
                if (primary) {
                    chip.setAttribute('aria-haspopup', 'menu');
                    chip.setAttribute('aria-expanded', String(expanded));
                    if (this.picker?.id) {
                        chip.setAttribute('aria-controls', this.picker.id);
                    }
                } else {
                    chip.removeAttribute('aria-haspopup');
                    chip.removeAttribute('aria-expanded');
                    chip.removeAttribute('aria-controls');
                }
            }
            this.primaryButton = button;
        }

        openPicker(moveFocus) {
            if (!this.picker || !this.primaryButton || this.busy) {
                return;
            }
            window.clearTimeout(this.closeTimer);
            this.picker.hidden = false;
            this.primaryButton.setAttribute('aria-expanded', 'true');
            if (moveFocus) {
                (this.choices.get(this.selected) || this.choices.values().next().value)?.focus();
            }
        }

        closePicker(returnFocus) {
            if (!this.picker || !this.primaryButton || this.picker.hidden) {
                return;
            }
            window.clearTimeout(this.openTimer);
            this.picker.hidden = true;
            this.primaryButton.setAttribute('aria-expanded', 'false');
            this.suppressPrimaryClick = false;
            if (returnFocus) {
                this.primaryButton.focus();
            }
        }

        onKeyDown(event) {
            if (event.target === this.primaryButton && ['ArrowDown', 'ArrowUp'].includes(event.key)) {
                event.preventDefault();
                this.openPicker(true);
                return;
            }

            if (event.key === 'Escape' && !this.picker?.hidden) {
                event.preventDefault();
                this.closePicker(true);
                return;
            }

            const currentIndex = [...this.choices.values()].indexOf(document.activeElement);
            if (currentIndex < 0 || !['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) {
                return;
            }

            event.preventDefault();
            const buttons = [...this.choices.values()];
            let nextIndex = currentIndex;
            if (event.key === 'Home') {
                nextIndex = 0;
            } else if (event.key === 'End') {
                nextIndex = buttons.length - 1;
            } else {
                nextIndex = (currentIndex + (event.key === 'ArrowRight' ? 1 : -1) + buttons.length) % buttons.length;
            }
            buttons[nextIndex].focus();
        }

        setBusy(busy) {
            this.busy = busy;
            this.root.classList.toggle('is-busy', busy);
            this.root.setAttribute('aria-busy', String(busy));
            for (const button of this.root.querySelectorAll('button')) {
                button.disabled = busy;
            }
        }

        setStatus(message, error) {
            if (!this.status) {
                return;
            }
            this.status.textContent = message;
            this.root.classList.toggle('is-error', error);
        }

        destroy() {
            window.clearTimeout(this.openTimer);
            window.clearTimeout(this.closeTimer);
            window.clearTimeout(this.longPressTimer);
            document.removeEventListener('pointerdown', this.documentPointerHandler);
        }
    }

    function rootsWithin(scope) {
        const roots = [];
        if (scope instanceof Element && scope.matches('[data-register-reactions]')) {
            roots.push(scope);
        }
        roots.push(...scope.querySelectorAll('[data-register-reactions]'));

        return roots;
    }

    function enhance(scope = document) {
        for (const root of rootsWithin(scope)) {
            if (!widgets.has(root)) {
                widgets.set(root, new ReactionWidget(root));
            }
        }
    }

    function destroy(scope = document) {
        for (const root of rootsWithin(scope)) {
            const widget = widgets.get(root);
            if (widget) {
                widget.destroy();
                widgets.delete(root);
            }
        }
    }

    window.RegisterReactions = Object.freeze({enhance, destroy});
    enhance(document);
})();
