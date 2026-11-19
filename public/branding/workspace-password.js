const workspacePassword = document.getElementById('workspace-password');
const passwordToggle = document.getElementById('workspace-password-toggle');

if (workspacePassword && passwordToggle) {
    passwordToggle.hidden = false;
    passwordToggle.addEventListener('click', () => {
        const show = workspacePassword.type === 'password';
        workspacePassword.type = show ? 'text' : 'password';
        passwordToggle.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
        passwordToggle.setAttribute('aria-pressed', String(show));
    });
}
