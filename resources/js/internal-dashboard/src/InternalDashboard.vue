<script setup lang="ts">
import Header from '@/components/Header/Header.vue'
import QrCodeModal from '@/components/QrCodeModal.vue'
import ModifiedReplayModal from '@/components/ModifiedReplayModal.vue'
import LogDetail from '@/components/Requests/LogDetail.vue'
import {exampleSubdomains, exampleUser} from './lib/devUtils';
import {computed, onMounted, ref} from 'vue';
import {isEmptyObject} from './lib/utils';
import EmptyState from './components/Requests/EmptyState.vue';
import Sidebar from "@/components/Sidebar/Sidebar.vue";

const props = defineProps<{
    pageData?: InternalDashboardPageData
}>();

const page: InternalDashboardPageData = {
    subdomains: props.pageData?.subdomains ?? exampleSubdomains(),
    user: props.pageData?.user ?? exampleUser(),
    max_logs: props.pageData?.max_logs ?? 100,
    local_url: props.pageData?.local_url ?? 'http://localhost',
    auth_token: props.pageData?.auth_token,
    platform_url: props.pageData?.platform_url ?? 'https://expose.dev',
};

const fallbackBanner: BannerData = {
    message: 'You are currently using the free version of Expose.',
    cta_text: 'Upgrade to Expose Pro',
    cta_url: 'https://expose.dev/get-pro',
    cta_suffix: 'to get access to our fast global network, custom domains, infinite tunnel duration and more.',
    background_color: 'bg-pink-600',
    text_color: 'text-white',
    background_style: '#db2777',
    text_style: '#ffffff',
};

const bannerStyle = computed(() => {
    if (!banner.value) return {};
    return {
        backgroundColor: banner.value.background_style,
        color: banner.value.text_style,
    };
});

const currentLog = ref(null as ExposeLog | null)
const search = ref('' as string)
const header = ref()
const sidebar = ref()
const qrCodeModal = ref()
const modifiedReplayModal = ref()
const banner = ref<BannerData | null>(null)

const fetchBanner = async () => {
    if (!page.auth_token || !page.platform_url) {
        // No token available, use fallback for free users
        if (!page.user.can_specify_subdomains) {
            banner.value = fallbackBanner;
        }
        return;
    }

    try {
        const response = await fetch(`${page.platform_url}/api/client/banner`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
            },
            body: JSON.stringify({ token: page.auth_token }),
        });

        if (response.ok) {
            const data = await response.json();
            banner.value = data.data?.banner ?? null;
        } else {
            // API error, use fallback for free users
            if (!page.user.can_specify_subdomains) {
                banner.value = fallbackBanner;
            }
        }
    } catch (error) {
        // Network error, use fallback for free users
        if (!page.user.can_specify_subdomains) {
            banner.value = fallbackBanner;
        }
    }
};

onMounted(() => {
    window.addEventListener('keydown', setupKeybindings);

    const pageTitle = 'Sharing ' + page.local_url.substring(page.local_url.indexOf('://') + 3) + ' - Expose';
    document.title = pageTitle;

    fetchBanner();
});

const setLog = (log: ExposeLog | null) => {
    currentLog.value = log;
}

const showQrCode = () => {
    qrCodeModal.value.show = true;
}

const showModifiedReplay = () => {
    modifiedReplayModal.value.show = true;
}


const setupKeybindings = (event: KeyboardEvent) => {
    const target = event.target as HTMLElement;

    if (
        target.tagName === 'INPUT' ||
        target.tagName === 'TEXTAREA' ||
        target.isContentEditable
    ) {
        return;
    }

    if (event.key === 'ArrowDown') {
        event.preventDefault();
        sidebar.value.nextLog()
    } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        sidebar.value.previousLog()
    } else if (event.key === 'o' && !event.metaKey && !event.ctrlKey) {
        header.value.openSubdomainInNewTab()
    } else if (event.key === 'l' && (event.metaKey || event.ctrlKey)) {
        sidebar.value.clearLogs()
    } else if (event.key === 'l') {
        header.value.copySubdomainToClipboard()
    } else if (event.key === 'f' && !event.metaKey && !event.ctrlKey) {
        sidebar.value.toggleFollowRequests()
    } else if (event.key === 'q' && !event.metaKey && !event.ctrlKey) {
        showQrCode()
    } else if (event.key === 'r' && !event.metaKey && !event.ctrlKey && currentLog.value) {
        sidebar.value.replay(currentLog.value)
    } else if ((event.metaKey || event.ctrlKey) && event.key === 'k') {
        event.preventDefault();
        sidebar.value.focusSearch()
    }

}

const siteHeight = computed(() => {
    return banner.value ? 'h-[calc(100vh-160px)]' : 'h-[calc(100vh-81px)]';
})

</script>

<template>
    <div class="mx-auto h-screen overflow-hidden min-[2000px]:border-l min-[2000px]:border-r">
        <div v-if="banner"
             :class="[banner.background_color, banner.text_color, 'py-2 px-4 flex flex-col items-center justify-center font-medium text-lg text-center']"
             :style="bannerStyle">
            <p>{{ banner.message }}</p>
            <p class="font-bold">
                <a :href="banner.cta_url" class="underline">{{ banner.cta_text }}</a> {{ banner.cta_suffix }}
            </p>
        </div>
        <div class="h-full">
            <Header ref="header" :subdomains="page.subdomains" @search-updated="search = $event"
                    @show-qr-code="showQrCode"/>

            <div class="w-full flex items-start bg-white dark:bg-gray-900">
                <Sidebar ref="sidebar" :maxLogs="page.max_logs" :search="search" :currentLog="currentLog"
                         @set-log="setLog"
                         :class="siteHeight"
                />
                <div class="relative w-11/12 overflow-y-auto"
                     :class="siteHeight"
                >
                    <EmptyState v-if="isEmptyObject(currentLog)" :subdomains="page.subdomains"/>
                    <LogDetail v-else :log="currentLog" @replay="sidebar.replay" @modified-replay="showModifiedReplay"/>
                </div>
            </div>


            <Teleport to="body">
                <QrCodeModal ref="qrCodeModal" :subdomains="page.subdomains"/>
                <ModifiedReplayModal ref="modifiedReplayModal" :currentLog="currentLog"/>
            </Teleport>
        </div>
    </div>
</template>
