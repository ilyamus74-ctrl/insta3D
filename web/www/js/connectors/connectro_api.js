(function installConnectorsModule() {
    if (!window.CoreAPI) {
        return;
    }

    CoreAPI.connectors = CoreAPI.connectors || {};

    CoreAPI.connectors.initForm = function initForm() {
        const form = document.getElementById('connector-form');
        if (!form || form.__connectorsInit) {
            return;
        }
        form.__connectorsInit = true;

        const authSelect = form.querySelector('#connector_auth_type');
        const loginBlock = form.querySelector('[data-connector-auth="login"]');
        const tokenBlock = form.querySelector('[data-connector-auth="token"]');

        const syncVisibility = () => {
            const mode = authSelect ? authSelect.value : 'login';
            if (loginBlock) {
                loginBlock.style.display = mode === 'login' ? '' : 'none';
            }
            if (tokenBlock) {
                tokenBlock.style.display = mode === 'token' ? '' : 'none';
            }
        };

        if (authSelect) {
            authSelect.addEventListener('change', syncVisibility);
        }
        syncVisibility();
    };

    CoreAPI.pageInits = CoreAPI.pageInits || {};
    CoreAPI.pageInits.connectors = function connectorsInit() {
        CoreAPI.connectors.initForm();
    };
})();
