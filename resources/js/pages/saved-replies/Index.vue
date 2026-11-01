<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { MessageSquareText, Pencil, Plus, Search, Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import TableCard from '@/components/admin/TableCard.vue';
import EmptyState from '@/components/EmptyState.vue';
import PageHeader from '@/components/PageHeader.vue';
import ResourcePagination from '@/components/ResourcePagination.vue';
import PlaceholderLegend from '@/components/saved-replies/PlaceholderLegend.vue';
import SavedReplyFormDialog from '@/components/saved-replies/SavedReplyFormDialog.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { destroy, index } from '@/routes/saved-replies';
import type { Paginated } from '@/types/pagination';
import type {
    SavedReply,
    SavedReplyCategoryOption,
    SavedReplyFilters,
    SavedReplyScope,
} from '@/types/saved-replies';

defineOptions({
    layout: { breadcrumbs: [{ title: 'Saved replies', href: index() }] },
});

const props = defineProps<{
    replies: Paginated<SavedReply>;
    filters: SavedReplyFilters;
    categories: SavedReplyCategoryOption[];
    canManageShared: boolean;
}>();

const search = ref(props.filters.q ?? '');
const scope = computed<SavedReplyScope>(() => props.filters.scope ?? 'mine');
const isSearching = computed(() => (props.filters.q ?? '') !== '');
const canCreateHere = computed(
    () => scope.value === 'mine' || props.canManageShared,
);

const dialogOpen = ref(false);
const editing = ref<SavedReply | null>(null);

function visit(query: { scope: SavedReplyScope; q: string }): void {
    const params: Record<string, string> = { scope: query.scope };

    if (query.q.trim() !== '') {
        params.q = query.q.trim();
    }

    router.get(index.url(), params, { preserveState: true, replace: true });
}

function changeScope(value: unknown): void {
    visit({ scope: value === 'team' ? 'team' : 'mine', q: search.value });
}

function applySearch(): void {
    visit({ scope: scope.value, q: search.value });
}

function clearSearch(): void {
    search.value = '';
    applySearch();
}

function openCreate(): void {
    editing.value = null;
    dialogOpen.value = true;
}

function openEdit(reply: SavedReply): void {
    editing.value = reply;
    dialogOpen.value = true;
}

function remove(reply: SavedReply): void {
    router.delete(destroy.url(reply.id), { preserveScroll: true });
}

const emptyTitle = computed(() => {
    if (isSearching.value) {
        return 'No saved replies match your search';
    }

    return scope.value === 'mine'
        ? 'You have no personal replies yet'
        : 'No team replies yet';
});

const emptyDescription = computed(() => {
    if (isSearching.value) {
        return 'Try a different word from the title or the text.';
    }

    if (scope.value === 'mine') {
        return 'Save answers you send often and insert them into any reply.';
    }

    return props.canManageShared
        ? 'Team replies are available to every agent.'
        : 'An administrator can add replies for the whole team.';
});
</script>

<template>
    <Head title="Saved replies" />

    <div class="mx-auto flex w-full max-w-7xl flex-col gap-6 p-4 md:p-6">
        <PageHeader
            title="Saved replies"
            description="Reusable answers you can insert into a ticket reply. Placeholders are filled in with the ticket’s details."
        >
            <template #actions>
                <Button v-if="canCreateHere" @click="openCreate"
                    ><Plus aria-hidden="true" />New reply</Button
                >
            </template>
        </PageHeader>

        <Tabs :model-value="scope" @update:model-value="changeScope">
            <div
                class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between"
            >
                <TabsList class="w-full md:w-auto">
                    <TabsTrigger value="mine">My replies</TabsTrigger>
                    <TabsTrigger value="team">Team replies</TabsTrigger>
                </TabsList>
                <form
                    role="search"
                    class="flex gap-2"
                    @submit.prevent="applySearch"
                >
                    <Input
                        v-model="search"
                        type="search"
                        maxlength="100"
                        placeholder="Search title or text…"
                        class="md:w-72"
                        aria-label="Search saved replies"
                    />
                    <Button type="submit" variant="outline"
                        ><Search aria-hidden="true" /><span
                            class="sr-only sm:not-sr-only"
                            >Search</span
                        ></Button
                    >
                </form>
            </div>

            <TabsContent :value="scope" class="mt-4 flex flex-col gap-4">
                <EmptyState
                    v-if="replies.data.length === 0"
                    :icon="MessageSquareText"
                    :title="emptyTitle"
                    :description="emptyDescription"
                >
                    <Button
                        v-if="isSearching"
                        variant="outline"
                        @click="clearSearch"
                        >Clear search</Button
                    >
                    <Button v-else-if="canCreateHere" @click="openCreate"
                        >Create a reply</Button
                    >
                </EmptyState>

                <template v-else>
                    <TableCard>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Title</TableHead>
                                    <TableHead class="hidden lg:table-cell"
                                        >Text</TableHead
                                    >
                                    <TableHead class="hidden md:table-cell"
                                        >Category</TableHead
                                    >
                                    <TableHead class="text-right"
                                        ><span class="sr-only"
                                            >Actions</span
                                        ></TableHead
                                    >
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                <TableRow
                                    v-for="reply in replies.data"
                                    :key="reply.id"
                                >
                                    <TableCell class="max-w-56 font-medium">
                                        <span class="block truncate">{{
                                            reply.title
                                        }}</span>
                                        <span
                                            class="block truncate text-xs font-normal text-muted-foreground lg:hidden"
                                            >{{ reply.body }}</span
                                        >
                                    </TableCell>
                                    <TableCell
                                        class="hidden max-w-md lg:table-cell"
                                    >
                                        <span
                                            class="line-clamp-2 text-sm whitespace-normal text-muted-foreground"
                                            >{{ reply.body }}</span
                                        >
                                    </TableCell>
                                    <TableCell class="hidden md:table-cell">
                                        <Badge
                                            v-if="reply.category"
                                            variant="outline"
                                            >{{ reply.category.name }}</Badge
                                        >
                                        <span
                                            v-else
                                            class="text-xs text-muted-foreground"
                                            >Any</span
                                        >
                                    </TableCell>
                                    <TableCell>
                                        <div class="flex justify-end gap-2">
                                            <Button
                                                v-if="reply.can.update"
                                                variant="outline"
                                                size="sm"
                                                @click="openEdit(reply)"
                                                ><Pencil
                                                    aria-hidden="true"
                                                /><span
                                                    class="sr-only sm:not-sr-only"
                                                    >Edit</span
                                                ></Button
                                            >
                                            <ConfirmDialog
                                                v-if="reply.can.delete"
                                                :title="`Delete “${reply.title}”?`"
                                                :description="
                                                    reply.scope === 'team'
                                                        ? 'This team reply will be removed for every agent.'
                                                        : 'This reply will be removed permanently.'
                                                "
                                                confirm-label="Delete"
                                                @confirm="remove(reply)"
                                            >
                                                <Button
                                                    variant="outline"
                                                    size="sm"
                                                    class="text-destructive"
                                                    ><Trash2
                                                        aria-hidden="true"
                                                    /><span
                                                        class="sr-only sm:not-sr-only"
                                                        >Delete</span
                                                    ></Button
                                                >
                                            </ConfirmDialog>
                                        </div>
                                    </TableCell>
                                </TableRow>
                            </TableBody>
                        </Table>
                    </TableCard>
                    <ResourcePagination
                        :links="replies.links"
                        :meta="replies.meta"
                        noun="replies"
                    />
                </template>
            </TabsContent>
        </Tabs>

        <Card>
            <CardHeader>
                <CardTitle class="text-base">Placeholders</CardTitle>
                <CardDescription
                    >Use these in the reply text. A word in braces that is not
                    listed here is rejected when you save.</CardDescription
                >
            </CardHeader>
            <CardContent><PlaceholderLegend /></CardContent>
        </Card>

        <SavedReplyFormDialog
            v-model:open="dialogOpen"
            :reply="editing"
            :categories="categories"
            :can-manage-shared="canManageShared"
            :default-shared="scope === 'team'"
        />
    </div>
</template>
