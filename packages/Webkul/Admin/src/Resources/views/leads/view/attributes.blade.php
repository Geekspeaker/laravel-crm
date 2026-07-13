{!! view_render_event('admin.leads.view.attributes.before', ['lead' => $lead]) !!}

<div class="flex w-full flex-col gap-4 border-b border-gray-300 p-4 dark:border-gray-800">
    <x-admin::accordion class="select-none !border-none">
        <x-slot:header class="!p-0">
            <div class="flex w-full items-center justify-between gap-4 font-semibold dark:text-white">
                <h4>@lang('admin::app.leads.view.attributes.title')</h4>
                
                @if (bouncer()->hasPermission('leads.edit'))
                    <div class="flex items-center gap-1">
                        <v-lead-ai-action
                            url="{{ route('admin.leads.enrich', $lead->id) }}"
                            label="🔎 Enrich"
                            :delay="20000"
                        ></v-lead-ai-action>

                        <v-lead-ai-action
                            url="{{ route('admin.leads.ai_draft', $lead->id) }}"
                            label="✨ AI Draft"
                            :delay="30000"
                        ></v-lead-ai-action>

                        <a
                            href="{{ route('admin.leads.edit', $lead->id) }}"
                            class="icon-edit rounded-md p-1.5 text-2xl transition-all hover:bg-gray-100 dark:hover:bg-gray-950"
                            target="_blank"
                        ></a>
                    </div>
                @endif
            </div>
        </x-slot>

        <x-slot:content class="mt-4 !px-0 !pb-0">
            @php
                // Show only the segment-relevant custom fields based on the lead's
                // source. Krayin attributes are global to all leads, so we hide the
                // fields that belong to the OTHER segments.
                $segmentCodes = [
                    'vc'      => ['fund_stage', 'check_size', 'thesis_fit'],
                    'partner' => ['affiliate_network', 'category', 'payout_model'],
                    'hr'      => ['company_size', 'industry', 'region'],
                ];

                $sourceName = strtolower(optional($lead->source)->name ?? '');

                if (str_contains($sourceName, 'vc') || str_contains($sourceName, 'invest')) {
                    $activeSegment = 'vc';
                } elseif (str_contains($sourceName, 'partner') || str_contains($sourceName, 'reward') || str_contains($sourceName, 'affiliate')) {
                    $activeSegment = 'partner';
                } elseif (str_contains($sourceName, 'hr') || str_contains($sourceName, 'work') || str_contains($sourceName, 'edu') || str_contains($sourceName, 'people')) {
                    $activeSegment = 'hr';
                } else {
                    $activeSegment = null;
                }

                $hiddenSegmentCodes = [];
                foreach ($segmentCodes as $seg => $codes) {
                    if ($seg !== $activeSegment) {
                        $hiddenSegmentCodes = array_merge($hiddenSegmentCodes, $codes);
                    }
                }

                $leadViewExcludeCodes = array_merge(
                    ['title', 'description', 'lead_pipeline_id', 'lead_pipeline_stage_id'],
                    $hiddenSegmentCodes
                );
            @endphp

            {!! view_render_event('admin.leads.view.attributes.form_controls.before', ['lead' => $lead]) !!}

            <x-admin::form
                v-slot="{ meta, errors, handleSubmit }"
                as="div"
                ref="modalForm"
            >
                <form @submit="handleSubmit($event, () => {})">
                    {!! view_render_event('admin.leads.view.attributes.form_controls.attributes.view.before', ['lead' => $lead]) !!}
        
                    <x-admin::attributes.view
                        :custom-attributes="app('Webkul\Attribute\Repositories\AttributeRepository')->findWhere([
                            'entity_type' => 'leads',
                            ['code', 'NOTIN', $leadViewExcludeCodes]
                        ])"
                        :entity="$lead"
                        :url="route('admin.leads.attributes.update', $lead->id)"
                        :allow-edit="true"
                    />
        
                    {!! view_render_event('admin.leads.view.attributes.form_controls.attributes.view.after', ['lead' => $lead]) !!}
                </form>
            </x-admin::form>
        
            {!! view_render_event('admin.leads.view.attributes.form_controls.after', ['lead' => $lead]) !!}
        </x-slot>
    </x-admin::accordion>
</div>

{!! view_render_event('admin.leads.view.attributes.before', ['lead' => $lead]) !!}


@pushOnce('scripts')
    <script
        type="text/x-template"
        id="v-lead-ai-action-template"
    >
        <button
            type="button"
            class="secondary-button flex items-center gap-1 text-sm"
            @click="run"
            :disabled="isLoading"
        >
            <span v-if="! isLoading">@{{ label }}</span>
            <span v-else>Working…</span>
        </button>
    </script>

    <script type="module">
        app.component('v-lead-ai-action', {
            template: '#v-lead-ai-action-template',

            props: {
                url: {
                    type: String,
                    required: true,
                },

                label: {
                    type: String,
                    default: 'Run',
                },

                delay: {
                    type: Number,
                    default: 30000,
                },
            },

            data() {
                return {
                    isLoading: false,
                };
            },

            methods: {
                run() {
                    if (this.isLoading) {
                        return;
                    }

                    this.isLoading = true;

                    this.$axios.post(this.url)
                        .then((response) => {
                            this.$emitter.emit('add-flash', {
                                type: 'success',
                                message: response.data.message,
                            });

                            // The job runs on the queue; give it time, then refresh
                            // so the generated fields show. Re-click if not ready yet.
                            setTimeout(() => window.location.reload(), this.delay);
                        })
                        .catch((error) => {
                            this.$emitter.emit('add-flash', {
                                type: 'error',
                                message: error.response?.data?.message || error.message,
                            });

                            this.isLoading = false;
                        });
                },
            },
        });
    </script>
@endPushOnce
