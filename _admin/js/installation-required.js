document.addEventListener('DOMContentLoaded', function () {
    document.querySelector('.toggle-config')?.addEventListener('click', function () {
        document.getElementById('configExample')?.classList.toggle('expanded');
    });
});
