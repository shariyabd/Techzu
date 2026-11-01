<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { LogOut, UserCog } from '@lucide/vue';
import {
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
} from '@/components/ui/dropdown-menu';
import UserInfo from '@/components/UserInfo.vue';
import { disconnectRealtime } from '@/lib/echo';
import { logout } from '@/routes';
import { edit as editProfile } from '@/routes/profile';
import type { User } from '@/types';

type Props = {
    user: User;
};

const handleLogout = () => {
    router.flushAll();
};

defineProps<Props>();
</script>

<template>
    <DropdownMenuLabel class="p-0 font-normal">
        <div class="flex items-center gap-2 px-1 py-1.5 text-left text-sm">
            <UserInfo :user="user" :show-email="true" />
        </div>
    </DropdownMenuLabel>
    <DropdownMenuSeparator />
    <DropdownMenuItem :as-child="true">
        <Link
            class="block w-full cursor-pointer"
            :href="editProfile()"
            data-test="profile-link"
        >
            <UserCog class="mr-2 h-4 w-4" />
            Profile settings
        </Link>
    </DropdownMenuItem>
    <DropdownMenuSeparator />
    <DropdownMenuItem :as-child="true">
        <Link
            class="block w-full cursor-pointer"
            :href="logout()"
            @click="handleLogout"
            @success="disconnectRealtime"
            as="button"
            data-test="logout-button"
        >
            <LogOut class="mr-2 h-4 w-4" />
            Log out
        </Link>
    </DropdownMenuItem>
</template>
