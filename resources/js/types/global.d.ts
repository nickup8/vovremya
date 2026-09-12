interface Window {
    __VK_GROUP_ID__?: string | number | null;
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            [key: string]: unknown;
        };
    }
}
