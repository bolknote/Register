// Only identity and HTTP responses are mocked. The rendered controls, stylesheet,
// public enhancement API and all reaction event handlers are production code.
window.RegisterVisitorIdentity = {
    ensure: async () => {},
    refresh: async () => {},
};
