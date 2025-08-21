// Allow typing spaces/dashes; server strips them before verify
document.getElementById('code')?.addEventListener('input', (e) => {
    e.target.value = e.target.value.replace(/[^\d\s-]/g, '').slice(0, 7);
});
