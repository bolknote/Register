(() => {
    'use strict';

    const clocks = Array.from('🕐🕜🕑🕝🕒🕞🕓🕟🕔🕠🕕🕡🕖🕢🕗🕣🕘🕤🕙🕥🕚🕦🕛🕧');
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    document.querySelectorAll('[data-files-archive-unicode-clock]').forEach((clock) => {
        let virtualMinutes = 0;

        const render = () => {
            const minutes = virtualMinutes % 60;
            const hours = Math.floor(virtualMinutes / 60) % 12;
            const picturedMinutes = minutes < 15 || minutes > 45 ? 0 : 30;
            const angle = (minutes - picturedMinutes) * 6;
            const picturedHours = Math.floor(hours - (minutes - picturedMinutes) / 5);
            const rawPicture = (picturedHours - 1) * 2 + (picturedMinutes === 30 ? 1 : 0);
            const picture = (rawPicture % clocks.length + clocks.length) % clocks.length;
            const displayHours = hours === 0 ? 12 : hours;

            clock.textContent = clocks[picture];
            clock.style.transform = `rotate(${angle}deg)`;
            clock.setAttribute(
                'aria-label',
                `Виртуальное время: ${displayHours}:${String(minutes).padStart(2, '0')}`,
            );
        };

        render();
        if (reducedMotion) {
            return;
        }

        window.setInterval(() => {
            virtualMinutes = (virtualMinutes + 1) % (12 * 60);
            render();
        }, 1000);
    });
})();
