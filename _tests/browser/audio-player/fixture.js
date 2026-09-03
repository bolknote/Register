const originalAudio = document.getElementById('main-audio');
const article = originalAudio.closest('article');
const audioTemplate = originalAudio.cloneNode(true);
const audios = new Set(document.querySelectorAll('audio'));
let mainAudio = originalAudio;

document.getElementById('remove-main').addEventListener('click', () => {
    (mainAudio.closest('.register-audio-player') || mainAudio).remove();
});
document.getElementById('restore-main').addEventListener('click', () => {
    if (mainAudio.isConnected) return;
    mainAudio = audioTemplate.cloneNode(true);
    audios.add(mainAudio);
    article.querySelector('.timestamps').before(mainAudio);
});
document.getElementById('theme').addEventListener('click', () => document.documentElement.classList.toggle('light'));

window.addEventListener('error', event => {
    document.getElementById('errors').textContent += `${event.message}\n`;
});
window.addEventListener('unhandledrejection', event => {
    document.getElementById('errors').textContent += `${event.reason}\n`;
});

setInterval(() => {
    document.getElementById('state').textContent = JSON.stringify(Array.from(audios, audio => ({
        id: audio.id,
        connected: audio.isConnected,
        currentTime: Number(audio.currentTime.toFixed(2)),
        duration: audio.duration,
        paused: audio.paused,
        readyState: audio.readyState,
        seeking: audio.seeking,
        error: audio.error?.message ?? null,
        buffered: Array.from({length: audio.buffered.length}, (_, index) => [
            Number(audio.buffered.start(index).toFixed(2)), Number(audio.buffered.end(index).toFixed(2)),
        ]),
    })), null, 2);
}, 250);

async function updateRequests() {
    try {
        const response = await fetch('/requests');
        document.getElementById('requests').textContent = JSON.stringify((await response.json()).slice(-6), null, 2);
    } finally {
        setTimeout(updateRequests, 500);
    }
}
updateRequests();
