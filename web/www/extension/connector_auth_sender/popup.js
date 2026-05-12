const systemUrlInput = document.getElementById('system-url');
const connectorIdInput = document.getElementById('connector-id');
const tokenKeysInput = document.getElementById('token-keys');
const statusEl = document.getElementById('status');
const sendBtn = document.getElementById('send-btn');
const saveBtn = document.getElementById('save-btn');
const profileSelect = document.getElementById('profile-select');
const profileNameInput = document.getElementById('profile-name');
const profileSaveBtn = document.getElementById('profile-save-btn');
const profileDeleteBtn = document.getElementById('profile-delete-btn');

const DEFAULT_PROFILE = 'default';


function setStatus(message, isError = false) {
    statusEl.textContent = message;
    statusEl.style.color = isError ? '#b00020' : '#2e7d32';
}

function parseTokenKeys(value) {
    return value
        .split(',')
        .map((key) => key.trim())
        .filter(Boolean);
}


function getProfilePayload() {
    return {
        systemUrl: systemUrlInput.value.trim(),
        connectorId: connectorIdInput.value.trim(),
        tokenKeys: tokenKeysInput.value.trim() || 'auth_token, token',
    };
}

function applyProfile(profile) {
    systemUrlInput.value = profile.systemUrl || '';
    connectorIdInput.value = profile.connectorId || '';
    tokenKeysInput.value = profile.tokenKeys || 'auth_token, token';
}

function renderProfiles(profiles, activeProfile) {
    profileSelect.innerHTML = '';
    const profileNames = Object.keys(profiles).sort();
    profileNames.forEach((name) => {
        const option = document.createElement('option');
        option.value = name;
        option.textContent = name;
        profileSelect.appendChild(option);
    });
    profileSelect.value = activeProfile;
}

async function loadSettings() {
    const data = await chrome.storage.sync.get([
        'profiles',
        'activeProfile',
        'systemUrl',
        'connectorId',
        'tokenKeys',
    ]);
    let profiles = data.profiles || {};
    let activeProfile = data.activeProfile || DEFAULT_PROFILE;

    if (Object.keys(profiles).length === 0) {
        profiles = {
            [DEFAULT_PROFILE]: {
                systemUrl: data.systemUrl || '',
                connectorId: data.connectorId || '',
                tokenKeys: data.tokenKeys || 'auth_token, token',
            },
        };
        activeProfile = DEFAULT_PROFILE;
    }

    renderProfiles(profiles, activeProfile);
    applyProfile(profiles[activeProfile] || profiles[DEFAULT_PROFILE]);
    await chrome.storage.sync.set({ profiles, activeProfile });
}

async function saveSettingsToProfile(profileName) {
    const data = await chrome.storage.sync.get(['profiles', 'activeProfile']);
    const profiles = data.profiles || {};
    const updatedName = profileName || data.activeProfile || DEFAULT_PROFILE;

    profiles[updatedName] = getProfilePayload();
    await chrome.storage.sync.set({
        profiles,
        activeProfile: updatedName,
        systemUrl: profiles[updatedName].systemUrl,
        connectorId: profiles[updatedName].connectorId,
        tokenKeys: profiles[updatedName].tokenKeys,
    });
    renderProfiles(profiles, updatedName);
    profileSelect.value = updatedName;
}

async function getActiveTab() {
    const [tab] = await chrome.tabs.query({ active: true, currentWindow: true });
    return tab;
}

async function getCookiesForUrl(url) {
    const cookies = await chrome.cookies.getAll({ url });
    return cookies.map((cookie) => `${cookie.name}=${cookie.value}`).join('; ');
}

async function getTokenFromPage(tabId, keys) {
    const results = await chrome.scripting.executeScript({
        target: { tabId },
        func: (tokenKeys) => {
            const keysList = Array.isArray(tokenKeys) ? tokenKeys : [];
            for (const key of keysList) {
                const value = localStorage.getItem(key) || sessionStorage.getItem(key);
                if (value) {
                    return value;
                }
            }
            return '';
        },
        args: [keys],
    });
    return results?.[0]?.result || '';
}

async function sendAuthData(systemUrl, connectorId, authToken, authCookies) {
    const formData = new FormData();
    formData.append('action', 'manual_confirm_extension');
    formData.append('connector_id', connectorId);
    formData.append('auth_token', authToken);
    formData.append('auth_cookies', authCookies);
    formData.append('auth_token_expires_at', '');

    const response = await fetch(`${systemUrl.replace(/\/$/, '')}/core_api.php`, {
        method: 'POST',
        body: formData,
        credentials: 'include',
    });
    return response.json();
}

sendBtn.addEventListener('click', async () => {
    setStatus('Собираем данные...');
    const systemUrl = systemUrlInput.value.trim();
    const connectorId = connectorIdInput.value.trim();
    const tokenKeys = parseTokenKeys(tokenKeysInput.value || '');

    if (!systemUrl || !connectorId) {
        setStatus('Укажите адрес системы и ID коннектора.', true);
        return;
    }

    try {
        await saveSettingsToProfile(profileSelect.value);
        const tab = await getActiveTab();
        if (!tab?.id || !tab.url) {
            setStatus('Не удалось получить активную вкладку.', true);
            return;
        }

        const [authCookies, authToken] = await Promise.all([
            getCookiesForUrl(tab.url),
            getTokenFromPage(tab.id, tokenKeys),
        ]);

        if (!authCookies && !authToken) {
            setStatus('Токены или cookies не найдены.', true);
            return;
        }

        const result = await sendAuthData(systemUrl, connectorId, authToken, authCookies);
        if (result?.status === 'ok') {
            setStatus('Данные отправлены успешно.');
        } else {
            setStatus(result?.message || 'Ошибка отправки данных.', true);
        }
    } catch (error) {
        setStatus(`Ошибка: ${error.message}`, true);
    }
});


saveBtn.addEventListener('click', async () => {
    await saveSettingsToProfile(profileSelect.value);
    setStatus('Настройки сохранены.');
});

profileSaveBtn.addEventListener('click', async () => {
    const name = profileNameInput.value.trim();
    if (!name) {
        setStatus('Укажите имя профиля.', true);
        return;
    }
    await saveSettingsToProfile(name);
    profileNameInput.value = '';
    setStatus(`Профиль "${name}" сохранён.`);
});

profileDeleteBtn.addEventListener('click', async () => {
    const data = await chrome.storage.sync.get(['profiles', 'activeProfile']);
    const profiles = data.profiles || {};
    const activeProfile = data.activeProfile || DEFAULT_PROFILE;

    if (activeProfile === DEFAULT_PROFILE) {
        setStatus('Нельзя удалить профиль по умолчанию.', true);
        return;
    }

    delete profiles[activeProfile];
    const remainingProfiles = Object.keys(profiles);
    const nextProfile = remainingProfiles[0] || DEFAULT_PROFILE;
    if (!profiles[nextProfile]) {
        profiles[nextProfile] = getProfilePayload();
    }

    await chrome.storage.sync.set({
        profiles,
        activeProfile: nextProfile,
        systemUrl: profiles[nextProfile].systemUrl,
        connectorId: profiles[nextProfile].connectorId,
        tokenKeys: profiles[nextProfile].tokenKeys,
    });

    renderProfiles(profiles, nextProfile);
    applyProfile(profiles[nextProfile]);
    setStatus('Профиль удалён.');
});

profileSelect.addEventListener('change', async (event) => {
    const name = event.target.value;
    const data = await chrome.storage.sync.get(['profiles']);
    const profiles = data.profiles || {};
    const profile = profiles[name];
    if (profile) {
        applyProfile(profile);
        await chrome.storage.sync.set({ activeProfile: name });
    }
});

loadSettings();

