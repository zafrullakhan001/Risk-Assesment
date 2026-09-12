<?php

declare(strict_types=1);

/**
 * Maps a Ticket Dossier parsed_json payload into the Product & Design Summary template.
 */
final class ProjectSummaryMapper
{
    public const MISSING = 'Not available in dossier';

    /**
     * Values that look like ServiceNow checkbox / flag noise rather than useful summary text.
     *
     * @var list<string>
     */
    private const WEAK_ANSWERS = [
        '-1', '1', '0',
    ];

    /**
     * Common PDF label-bleed prefixes that do not belong as values for other fields.
     *
     * @var list<string>
     */
    private const FOREIGN_LABEL_PREFIXES = [
        'submitted by:',
        'product(s) name:',
        'reporting flags:',
        'location address(s):',
        'location address:',
        'quarterly committment:',
        'quarterly commitment:',
        'impacted ai systems:',
        'related list title:',
        'converted to:',
        'eap details team:',
        'metric list:',
        'impacted business applications:',
        'impacted end users:',
        'entity services:',
    ];

    /**
     * @param array<string, mixed> $project Row from projects table
     * @param array<string, mixed> $parsed  Decoded parsed_json
     * @return array{
     *   product_summary: array<string, array{label: string, value: string, available: bool}>,
     *   business_requirements: array<string, array{label: string, value: string, available: bool}>,
     *   key_questions: list<array{label: string, value: string, available: bool}>,
     *   design_includes: list<array{label: string, value: string, available: bool, tone: string}>,
     *   goals: array<string, array{label: string, value: string, available: bool}>,
     *   integrations: list<array{label: string, value: string, available: bool}>,
     *   third_party_review: array<string, array{label: string, value: string, available: bool}>,
     *   owners: array<string, array{label: string, value: string, available: bool}>,
     *   vendor_commitments: array{note: string, items: list<array{label: string, value: string, available: bool}>}
     * }
     */
    public static function map(array $project, array $parsed): array
    {
        $overview = self::sectionMap($parsed['overview'] ?? null);
        $demand = self::sectionFields($parsed['demand'] ?? null);
        $story = self::sectionFields($parsed['story'] ?? null);
        $task = self::sectionFields($parsed['task'] ?? null);
        $ddr = self::sectionFields($parsed['ddr'] ?? null);
        $vendor = self::vendorFields($parsed['vendor'] ?? null);
        $qaIndex = self::buildQaIndex($parsed['assessments'] ?? null);

        $demandDesc = self::sectionText($parsed['demand'] ?? null, 'description');
        $storyDesc = self::sectionText($parsed['story'] ?? null, 'description');
        $taskDesc = self::sectionText($parsed['task'] ?? null, 'description');
        $ddrDesc = self::sectionText($parsed['ddr'] ?? null, 'description');
        $demandCase = self::sectionText($parsed['demand'] ?? null, 'business_case');

        $productName = self::firstAvailable(
            self::pick($demand, 'Product(s) Name', 'Application Name', 'Name', 'Short Description'),
            self::pick($ddr, 'Engagement', 'Engagement name'),
            self::pick($story, 'Short Description'),
            self::pick($task, 'Short Description'),
            (string) ($overview['title'] ?? ''),
            (string) ($project['title'] ?? '')
        );

        $purpose = self::firstAvailable(
            (string) ($overview['description'] ?? ''),
            $demandDesc,
            self::pick($demand, 'Description', 'Detailed Description', 'Short Description'),
            $ddrDesc,
            self::pick($ddr, 'Description', 'Solution Description', 'Short Description'),
            $storyDesc,
            $taskDesc
        );

        $coreFeatures = self::firstAvailable(
            self::joinNonEmpty([
                self::pick($demand, 'Impacted Business Applications', 'Impacted Products'),
                self::qaAnswer($qaIndex, ['solution technology design', 'a1 -'], true),
                self::qaAnswer($qaIndex, ['solution deployment document', 'architecture diagram'], true),
            ]),
            self::pick($ddr, 'Solution Description'),
            self::pick($demand, 'Product(s) Name', 'Application Name')
        );

        $valueProposition = self::firstAvailable(
            $demandCase,
            self::pick($demand, 'Business Case', 'Goals & Benefits', 'Primary goal'),
            (string) ($overview['business_case'] ?? ''),
            self::pick($ddr, 'Solution Description')
        );

        $targetAudience = self::firstAvailable(
            self::joinNonEmpty([
                self::pick($demand, 'Impacted End Users'),
                self::pick($demand, 'Business Unit'),
                self::pick($demand, 'Division/Region'),
                self::pick($demand, 'Facility'),
                self::pick($demand, 'Entity Type'),
            ]),
            self::pick($demand, 'Number of Physicians', 'Net New Provider Count')
        );

        $appShortName = self::firstAvailable(
            self::pick($demand, 'App Short Name', 'Application Short Name', 'Short Name'),
            self::pick($ddr, 'App Short Name', 'Application Short Name')
        );

        $businessGoals = self::firstAvailable(
            self::pick($demand, 'Business Case', 'Primary goal', 'Goals & Benefits'),
            $demandCase,
            (string) ($overview['business_case'] ?? '')
        );

        $designChoices = self::firstAvailable(
            self::qaAnswer($qaIndex, ['solution technology design', 'a1 -'], true),
            self::qaAnswer($qaIndex, ['deployment model', 'hosting model', 'cloud or on-prem'], true),
            self::qaAnswer($qaIndex, ['dependencies', 'm1 -'], true)
        );

        $stakeholderInputs = self::joinNonEmpty([
            self::labelValue('Submitted By', self::pick($demand, 'Submitted By')),
            self::labelValue('Requesting VP', self::pick($demand, 'Requesting VP')),
            self::labelValue('Business Owner', self::pick($demand, 'Business Owner')),
            self::labelValue('AIT Executive Sponsor', self::pick($demand, 'AIT Executive Sponsor')),
            self::labelValue('AIT Product Owner', self::pick($demand, 'AIT Product Owner')),
            self::labelValue('AIT Product Manager', self::pick($demand, 'AIT Product Manager')),
            self::labelValue('AIT Demand Manager', self::pick($demand, 'AIT Demand Manager')),
            self::labelValue('Requested by', self::pick($story, 'Requested by')),
            self::labelValue('Assignee', self::pick($story, 'Assignee', 'Assigned to')),
        ]);

        $adGroups = self::firstAvailable(
            self::qaAnswer($qaIndex, ['ad group', 'active directory group', 'b2 - 1.3', 'c2 - 1.3', 'd2 - 1.4'], true),
            self::qaAnswer($qaIndex, ['saml', 'oidc', 'secure ldap'], true)
        );

        $serviceAccounts = self::firstAvailable(
            self::qaAnswer($qaIndex, ['service account', 'service accounts'], true),
            self::qaAnswer($qaIndex, ['user account automation', 'account provisioning'], true)
        );

        $securityExceptions = self::firstAvailable(
            self::pick($demand, 'Exception Status'),
            self::pick($story, 'Exception Status'),
            self::pick($task, 'Exception Status'),
            self::qaAnswer($qaIndex, ['security exception', 'known security exception'], true)
        );

        $webUrlPrefs = self::firstAvailable(
            self::qaAnswer($qaIndex, ['url to the website', 'a2 -'], true),
            self::qaAnswer($qaIndex, ['url to the diagram', 'url naming', 'web url'], true)
        );

        $drivingFactors = self::joinNonEmpty([
            self::labelValue('Core Level', self::pick($ddr, 'Core Level', 'Core level') ?: self::pick($demand, 'Core Level')),
            self::labelValue('Core 4 Documentation', self::pick($demand, 'Core 4 Documentation')),
            self::labelValue('Aligned Governance Committee', self::pick($demand, 'Aligned Governance Committee')),
            self::labelValue('Governance Committee Decision', self::pick($demand, 'Governance Committee Decision')),
            self::labelValue('Priority Alignment', self::pick($demand, 'Priority Alignment')),
            self::labelValue('Funding Status', self::pick($demand, 'Funding Status')),
            self::labelValue('AI Enabled Technologies', self::pick($demand, 'AI Enabled Technologies') ?: self::pick($ddr, 'Is AI Enabled Technologies')),
        ]);

        $designConstraints = self::firstAvailable(
            self::joinNonEmpty([
                self::qaAnswer($qaIndex, ['bandwidth', 'latency', 'a4 -', 'network constraint'], true),
                self::pick($demand, 'On Hold Reason'),
                self::labelValue('Risk rating', self::pick($ddr, 'Risk rating', 'Engagement rating')),
            ])
        );

        $coreLevel = self::firstAvailable(
            self::pick($ddr, 'Core Level', 'Core level'),
            self::pick($demand, 'Core Level', 'Core 4 Documentation')
        );

        $locations = self::joinNonEmpty([
            self::pick($demand, 'Facility'),
            self::pick($demand, 'Location Address(s)', 'Location Address', 'Location Address(es)'),
            self::pick($demand, 'Division/Region'),
            self::pick($demand, 'Entity Services'),
            self::joinNonEmpty([
                self::pick($vendor, 'Street'),
                self::pick($vendor, 'City'),
                self::pick($vendor, 'State / Province', 'State'),
                self::pick($vendor, 'Country'),
                self::pick($vendor, 'Zip / postal code', 'Zip'),
            ], ', '),
        ]);

        $vlans = self::qaAnswer($qaIndex, ['isolated vlans', 'vlan required', 'known vlan'], true);

        $serverSpecs = self::firstAvailable(
            self::qaAnswer($qaIndex, ['supported os', 'operating system version', 'os versions are required'], true),
            self::qaAnswer($qaIndex, ['server specs', 'server specification', 'ram and cpu', 'cpu and ram'], true)
        );

        $userVolume = self::pick($demand, 'Impacted End Users');

        $vendorPra = self::qaAnswer($qaIndex, [
            'bomgar',
            'vendor pra',
            'pra remote access',
            'remote access required',
            'vendor remote access',
        ], true);

        $installDocs = self::qaAnswer($qaIndex, [
            'installation guides',
            'installation documentation',
            'solution deployment document',
            'a3 -',
        ], true);

        $sso = self::firstAvailable(
            self::qaAnswer($qaIndex, ['saml 2.0', 'wsfed', 'secure ldap', 'single sign-on', 'single sign on'], true),
            self::qaAnswer($qaIndex, ['sso required', 'sso support', 'mfa required'], true)
        );

        $aia = self::firstAvailable(
            self::pick($demand, 'AI Enabled Technologies'),
            self::pick($ddr, 'Is AI Enabled Technologies', 'AI assets involved'),
            self::qaAnswer($qaIndex, ['artificial intelligence', 'ai solution'], true),
            self::meaningfulAiRecommendation(self::pick($ddr, 'AI Recommendation'))
        );

        $sla = self::firstAvailable(
            self::joinNonEmpty([
                self::labelValue('Made SLA', self::pick($ddr, 'Made SLA')),
                self::labelValue('SLA due', self::pick($ddr, 'SLA due')),
            ]),
            self::qaAnswer($qaIndex, ['documented sla', 'service level agreement', 'kpi report'], true)
        );

        $goLive = self::firstAvailable(
            self::pick($demand, 'QP-Go-Live', 'Target Project Finish', 'Target Project Start'),
            self::pick($ddr, 'Go-Live', 'Planned end date', 'Planned start date'),
            self::pick($story, 'Planned end date', 'Planned start date'),
            self::pick($task, 'Planned end date', 'Planned start date')
        );

        $shortTermGoals = self::firstAvailable(
            self::pick($demand, 'Primary goal', 'Goals & Benefits'),
            self::pick($story, 'Acceptance criteria', 'Validation plan'),
            self::pick($task, 'Acceptance criteria')
        );

        $longTermGoals = self::firstAvailable(
            self::qaAnswer($qaIndex, ['strategic, competitive or operational advantage'], true),
            self::joinNonEmpty([
                self::labelValue('Primary target', self::pick($demand, 'Primary target')),
                self::labelValue('Program', self::pick($demand, 'Program')),
                self::labelValue('Portfolio', self::pick($demand, 'Portfolio')),
            ])
        );

        $epic = self::firstAvailable(
            self::qaAnswer($qaIndex, ['cerner millenium', 'cerner millennium', 'hl7', 'fhir', 'ccda', 'dicom'], true),
            self::integrationHint(self::pick($demand, 'Impacted Business Applications', 'Epic'), ['epic', 'cerner', 'hl7', 'fhir', 'emr', 'ehr'])
        );

        $lis = self::firstAvailable(
            self::qaAnswer($qaIndex, ['clsi lis02-a2', 'laboratory information system', 'lis02'], true),
            self::containsHint(self::pick($demand, 'Impacted Business Applications'), 'LIS')
                ? self::pick($demand, 'Impacted Business Applications')
                : ''
        );

        $tprm = self::firstAvailable(
            self::joinNonEmpty([
                self::labelValue('Recommendation', self::pick($ddr, 'TPRM Recommendation')),
                self::labelValue('Review Date', self::pick($ddr, 'TPRM Review Date')),
                self::labelValue('Assignee', self::pick($ddr, 'TPRM Assignee')),
                self::labelValue('Status', self::pick($ddr, 'Engagement risk assessment status')),
                self::labelValue('Submitted to third party', self::pick($ddr, 'Submitted to third party')),
            ]),
            self::pick($ddr, 'TPRM Recommendation')
        );

        $techReview = self::firstAvailable(
            self::joinNonEmpty([
                self::labelValue('Recommendation', self::pick($ddr, 'Technology Recommendation', 'TR Recommendation')),
                self::labelValue('Review Date', self::pick($ddr, 'TR Review Date')),
                self::labelValue('Assignee', self::pick($ddr, 'TR Assignee')),
            ]),
            self::pick($ddr, 'Technology Recommendation', 'TR Recommendation')
        );

        $grcLink = self::firstAvailable(
            self::pick($ddr, 'GRC URL', 'ServiceNow URL', 'Profile URL'),
            self::pick($demand, 'GRC URL', 'ServiceNow URL')
        );

        $productOwner = self::firstAvailable(
            self::pick($demand, 'AIT Product Owner'),
            self::pick($story, 'Product Product Manager', 'Product Owner')
        );

        $businessOwner = self::pick($demand, 'Business Owner');

        $supportOwner = self::firstAvailable(
            self::pick($demand, 'Support Owner', 'Support Lead'),
            self::pick($task, 'Support Owner')
        );

        $vendorWebsite = self::firstAvailable(
            self::qaAnswer($qaIndex, ['url to the website', 'a2 -', 'vendor website'], true),
            self::pick($vendor, 'Website', 'URL')
        );

        $vendorSupport = self::firstAvailable(
            self::qaAnswer($qaIndex, ['vendor support', 'support methods', 'support phone', 'support form'], true),
            self::pick($vendor, 'Support phone', 'Support email', 'Support URL')
        );

        $linuxAccounts = self::firstAvailable(
            self::qaAnswer($qaIndex, ['linux super user', 'super user account', 'root account'], true)
        );

        // Prefer explicit Linux super-user answers only.

        return [
            'product_summary' => [
                'product_name' => self::field('Product Name', $productName),
                'purpose' => self::field('Purpose', $purpose),
                'core_features' => self::field('Core Features/Technology', $coreFeatures),
                'value_proposition' => self::field('Value Proposition', $valueProposition),
                'target_audience' => self::field('Target Audience', $targetAudience),
                'app_short_name' => self::field('App Short Name', $appShortName),
            ],
            'business_requirements' => [
                'business_goals' => self::field('Business Goals', $businessGoals),
                'design_choices' => self::field('Design Choices', $designChoices),
                'stakeholder_inputs' => self::field('Stakeholder Inputs', $stakeholderInputs),
                'driving_factors' => self::field('Driving factors', $drivingFactors),
                'design_constraints' => self::field('Design constraints, if any', $designConstraints),
            ],
            'key_questions' => [
                self::field('AD groups?', $adGroups),
                self::field('Service accounts?', $serviceAccounts),
                self::field('Are there any known security exceptions?', $securityExceptions),
                self::field('Web URL naming preferences?', $webUrlPrefs),
            ],
            'design_includes' => [
                self::checklist('Project Core [1, 2, 3, 4]', $coreLevel, 'green'),
                self::checklist('Locations and facilities', $locations, 'green'),
                self::checklist('Known VLANs', $vlans, 'green'),
                self::checklist('Server specs (RAM, CPU, OS)', $serverSpecs, 'green'),
                // Storage requirements intentionally omitted.
                self::checklist('User volume', $userVolume, 'green'),
                self::checklist('Vendor PRA access', $vendorPra, 'green'),
                self::checklist('Installation documentation', $installDocs, 'green'),
                self::checklist('SSO requirements', $sso, 'blue'),
                self::checklist('AIA', $aia, 'gold'),
                self::checklist('SLA definitions', $sla, 'gold'),
                self::checklist('Go-Live date', $goLive, 'gold'),
            ],
            'goals' => [
                'short_term' => self::field('Short Term', $shortTermGoals),
                'long_term' => self::field('Long Term', $longTermGoals),
            ],
            'integrations' => [
                self::field('EMR / EPIC', $epic),
                self::field('LIS', $lis),
            ],
            'third_party_review' => [
                'tprm' => self::field('TPRM', $tprm),
                'technology_review' => self::field('Technology Review', $techReview),
                'grc_profile' => self::field('GRC / ServiceNow profile', $grcLink),
            ],
            'owners' => [
                'product_owner' => self::field('Product Owner', $productOwner),
                'business_owner' => self::field('Business Owner', $businessOwner),
                'support_owner' => self::field('Support Owner', $supportOwner),
            ],
            'vendor_commitments' => [
                'note' => 'Dependent on completion of 3rd Party Review and their support strategy.',
                'items' => [
                    self::field('Vendor website', $vendorWebsite),
                    self::field('Vendor support methods (phone, website, form)', $vendorSupport),
                    self::field('Linux super user accounts', $linuxAccounts),
                ],
            ],
        ];
    }

    /**
     * Compact draft for Gemma prompts (label/value only).
     *
     * @param array<string, mixed> $mapped
     * @return array<string, mixed>
     */
    public static function toAiHints(array $mapped): array
    {
        $out = [];
        foreach (['product_summary', 'business_requirements', 'goals', 'third_party_review', 'owners'] as $section) {
            if (!isset($mapped[$section]) || !is_array($mapped[$section])) {
                continue;
            }
            $out[$section] = [];
            foreach ($mapped[$section] as $key => $field) {
                if (!is_array($field)) {
                    continue;
                }
                $out[$section][$key] = [
                    'label' => (string) ($field['label'] ?? $key),
                    'value' => !empty($field['available']) ? (string) ($field['value'] ?? '') : '',
                    'available' => !empty($field['available']),
                ];
            }
        }

        if (isset($mapped['key_questions']) && is_array($mapped['key_questions'])) {
            $out['key_questions'] = [];
            foreach ($mapped['key_questions'] as $i => $field) {
                if (!is_array($field)) {
                    continue;
                }
                $out['key_questions'][] = [
                    'label' => (string) ($field['label'] ?? ''),
                    'value' => !empty($field['available']) ? (string) ($field['value'] ?? '') : '',
                ];
            }
        }

        if (isset($mapped['design_includes']) && is_array($mapped['design_includes'])) {
            $out['design_includes'] = [];
            foreach ($mapped['design_includes'] as $field) {
                if (!is_array($field)) {
                    continue;
                }
                $out['design_includes'][] = [
                    'label' => (string) ($field['label'] ?? ''),
                    'value' => !empty($field['available']) ? (string) ($field['value'] ?? '') : '',
                ];
            }
        }

        if (isset($mapped['integrations']) && is_array($mapped['integrations'])) {
            $out['integrations'] = [];
            foreach ($mapped['integrations'] as $i => $field) {
                if (!is_array($field)) {
                    continue;
                }
                $out['integrations'][] = [
                    'label' => (string) ($field['label'] ?? ''),
                    'value' => !empty($field['available']) ? (string) ($field['value'] ?? '') : '',
                ];
            }
        }

        if (isset($mapped['vendor_commitments']['items']) && is_array($mapped['vendor_commitments']['items'])) {
            $out['vendor_commitments'] = [];
            foreach ($mapped['vendor_commitments']['items'] as $field) {
                if (!is_array($field)) {
                    continue;
                }
                $out['vendor_commitments'][] = [
                    'label' => (string) ($field['label'] ?? ''),
                    'value' => !empty($field['available']) ? (string) ($field['value'] ?? '') : '',
                ];
            }
        }

        return $out;
    }

    /**
     * Overlay Gemma template string values onto the rule-mapped summary.
     * AI values win when non-empty; otherwise keep mapper values.
     *
     * @param array<string, mixed> $mapped
     * @param array<string, mixed>|null $aiTemplate
     * @return array<string, mixed>
     */
    public static function mergeWithAi(array $mapped, ?array $aiTemplate): array
    {
        if ($aiTemplate === null || $aiTemplate === []) {
            return $mapped;
        }

        $mapField = static function (array $field, string $aiValue): array {
            $aiValue = trim($aiValue);
            if ($aiValue === '' || strcasecmp($aiValue, self::MISSING) === 0) {
                $field['source'] = (string) ($field['source'] ?? 'mapper');

                return $field;
            }

            return [
                'label' => (string) ($field['label'] ?? ''),
                'value' => $aiValue,
                'available' => true,
                'source' => 'ai',
                'tone' => $field['tone'] ?? null,
            ];
        };

        $sectionMaps = [
            'product_summary' => [
                'product_name' => 'product_name',
                'purpose' => 'purpose',
                'core_features' => 'core_features',
                'value_proposition' => 'value_proposition',
                'target_audience' => 'target_audience',
                'app_short_name' => 'app_short_name',
            ],
            'business_requirements' => [
                'business_goals' => 'business_goals',
                'design_choices' => 'design_choices',
                'stakeholder_inputs' => 'stakeholder_inputs',
                'driving_factors' => 'driving_factors',
                'design_constraints' => 'design_constraints',
            ],
            'goals' => [
                'short_term' => 'short_term',
                'long_term' => 'long_term',
            ],
            'third_party_review' => [
                'tprm' => 'tprm',
                'technology_review' => 'technology_review',
                'grc_profile' => 'grc_profile',
            ],
            'owners' => [
                'product_owner' => 'product_owner',
                'business_owner' => 'business_owner',
                'support_owner' => 'support_owner',
            ],
        ];

        foreach ($sectionMaps as $section => $keys) {
            if (!isset($mapped[$section]) || !is_array($mapped[$section])) {
                continue;
            }
            $aiSection = is_array($aiTemplate[$section] ?? null) ? $aiTemplate[$section] : [];
            foreach ($keys as $mapKey => $aiKey) {
                if (!isset($mapped[$section][$mapKey]) || !is_array($mapped[$section][$mapKey])) {
                    continue;
                }
                $aiValue = (string) ($aiSection[$aiKey] ?? '');
                $merged = $mapField($mapped[$section][$mapKey], $aiValue);
                if (array_key_exists('tone', $merged) && $merged['tone'] === null) {
                    unset($merged['tone']);
                }
                $mapped[$section][$mapKey] = $merged;
            }
        }

        $listSpecs = [
            'key_questions' => ['ad_groups', 'service_accounts', 'security_exceptions', 'web_url_preferences'],
            'design_includes' => [
                'project_core', 'locations', 'vlans', 'server_specs', 'user_volume',
                'vendor_pra', 'install_docs', 'sso', 'aia', 'sla', 'go_live',
            ],
            'integrations' => ['emr_epic', 'lis'],
        ];

        foreach ($listSpecs as $section => $aiKeys) {
            if (!isset($mapped[$section]) || !is_array($mapped[$section])) {
                continue;
            }
            $aiSection = is_array($aiTemplate[$section] ?? null) ? $aiTemplate[$section] : [];
            foreach ($mapped[$section] as $i => $field) {
                if (!is_array($field) || !isset($aiKeys[$i])) {
                    continue;
                }
                $aiValue = (string) ($aiSection[$aiKeys[$i]] ?? '');
                $merged = $mapField($field, $aiValue);
                if (($merged['tone'] ?? null) === null) {
                    unset($merged['tone']);
                } else {
                    // checklist keeps tone
                }
                if (isset($field['tone'])) {
                    $merged['tone'] = $field['tone'];
                }
                $mapped[$section][$i] = $merged;
            }
        }

        if (isset($mapped['vendor_commitments']['items']) && is_array($mapped['vendor_commitments']['items'])) {
            $aiVendor = is_array($aiTemplate['vendor_commitments'] ?? null) ? $aiTemplate['vendor_commitments'] : [];
            $vendorKeys = ['vendor_website', 'vendor_support', 'linux_super_user'];
            foreach ($mapped['vendor_commitments']['items'] as $i => $field) {
                if (!is_array($field) || !isset($vendorKeys[$i])) {
                    continue;
                }
                $mapped['vendor_commitments']['items'][$i] = $mapField($field, (string) ($aiVendor[$vendorKeys[$i]] ?? ''));
                unset($mapped['vendor_commitments']['items'][$i]['tone']);
            }
        }

        return $mapped;
    }

    /**
     * @return array{label: string, value: string, available: bool, source: string}
     */
    private static function field(string $label, string $value): array
    {
        $trimmed = self::cleanValue($value);
        if ($trimmed === '') {
            return [
                'label' => $label,
                'value' => self::MISSING,
                'available' => false,
                'source' => 'mapper',
            ];
        }

        return [
            'label' => $label,
            'value' => $trimmed,
            'available' => true,
            'source' => 'mapper',
        ];
    }

    /**
     * @return array{label: string, value: string, available: bool, tone: string, source: string}
     */
    private static function checklist(string $label, string $value, string $tone): array
    {
        $field = self::field($label, $value);

        return [
            'label' => $field['label'],
            'value' => $field['value'],
            'available' => $field['available'],
            'tone' => $tone,
            'source' => $field['source'],
        ];
    }

    private static function cleanValue(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '' || isEmptyish($trimmed)) {
            return '';
        }

        // Label bleed from PDF parsing, e.g. "Reporting Flags:" or "Quarterly Committment:"
        if (preg_match('/^[A-Za-z][A-Za-z0-9 \\/&()_-]*:$/', $trimmed) === 1) {
            return '';
        }

        if (self::isEmptyLabelTemplate($trimmed)) {
            return '';
        }

        $lower = strtolower($trimmed);
        foreach (self::FOREIGN_LABEL_PREFIXES as $prefix) {
            if (str_starts_with($lower, $prefix)) {
                return '';
            }
        }

        if (in_array($lower, self::WEAK_ANSWERS, true)) {
            return '';
        }

        return $trimmed;
    }

    private static function isEmptyLabelTemplate(string $value): bool
    {
        $stripped = preg_replace('/[A-Za-z][A-Za-z0-9 \\/&()_-]{0,80}:\s*/u', '', $value);
        $stripped = trim(preg_replace('/\s+/u', ' ', (string) $stripped) ?? '');

        return $stripped === '' || isEmptyish($stripped);
    }

    /**
     * @param list<string> $needles
     */
    private static function integrationHint(string $value, array $needles): string
    {
        $clean = self::cleanValue($value);
        if ($clean === '') {
            return '';
        }
        $lower = strtolower($clean);
        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($lower, strtolower($needle))) {
                return $clean;
            }
        }

        return '';
    }

    private static function meaningfulAiRecommendation(string $value): string
    {
        $clean = self::cleanValue($value);
        if ($clean === '') {
            return '';
        }

        // Keep only if reviewer left real prose beyond the boilerplate labels.
        return self::isEmptyLabelTemplate($clean) ? '' : $clean;
    }

    /**
     * @param mixed $section
     * @return array<string, string>
     */
    private static function sectionFields(mixed $section): array
    {
        if (!is_array($section)) {
            return [];
        }

        $fields = $section['fields'] ?? null;
        if (!is_array($fields)) {
            return [];
        }

        // Already label => value
        $isList = array_is_list($fields);
        if (!$isList) {
            $out = [];
            foreach ($fields as $label => $value) {
                if (!is_string($label)) {
                    continue;
                }
                $out[$label] = normalizeDisplayValue($value);
            }

            return $out;
        }

        return fieldsToMap($fields);
    }

    /**
     * @param mixed $overview
     * @return array<string, string>
     */
    private static function sectionMap(mixed $overview): array
    {
        if (!is_array($overview)) {
            return [];
        }

        $out = [];
        foreach ($overview as $key => $value) {
            if (!is_string($key)) {
                continue;
            }
            $out[$key] = normalizeDisplayValue($value);
        }

        return $out;
    }

    /**
     * @param mixed $section
     */
    private static function sectionText(mixed $section, string $key): string
    {
        if (!is_array($section)) {
            return '';
        }

        return self::cleanValue(normalizeDisplayValue($section[$key] ?? ''));
    }

    /**
     * @param mixed $vendor
     * @return array<string, string>
     */
    private static function vendorFields(mixed $vendor): array
    {
        if (!is_array($vendor)) {
            return [];
        }

        if (isset($vendor['fields']) && is_array($vendor['fields'])) {
            return self::sectionFields(['fields' => $vendor['fields']]);
        }

        return self::sectionFields(['fields' => $vendor]);
    }

    /**
     * Case-insensitive field lookup with emptyish filtering.
     *
     * @param array<string, string> $map
     */
    private static function pick(array $map, string ...$labels): string
    {
        if ($map === [] || $labels === []) {
            return '';
        }

        $normalized = [];
        foreach ($map as $label => $value) {
            $normalized[strtolower(trim($label))] = (string) $value;
        }

        foreach ($labels as $label) {
            $key = strtolower(trim($label));
            if (!array_key_exists($key, $normalized)) {
                continue;
            }
            $value = self::cleanValue($normalized[$key]);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private static function firstAvailable(string ...$values): string
    {
        foreach ($values as $value) {
            $trimmed = self::cleanValue($value);
            if ($trimmed !== '') {
                return $trimmed;
            }
        }

        return '';
    }

    /**
     * @param list<string> $parts
     */
    private static function joinNonEmpty(array $parts, string $separator = "\n"): string
    {
        $clean = [];
        foreach ($parts as $part) {
            $trimmed = self::cleanValue($part);
            if ($trimmed !== '') {
                $clean[] = $trimmed;
            }
        }

        return implode($separator, $clean);
    }

    private static function labelValue(string $label, string $value): string
    {
        $trimmed = self::cleanValue($value);
        if ($trimmed === '') {
            return '';
        }

        return $label . ': ' . $trimmed;
    }

    /**
     * @param mixed $assessments
     * @return list<array{question: string, answer: string, question_lc: string}>
     */
    private static function buildQaIndex(mixed $assessments): array
    {
        if (!is_array($assessments)) {
            return [];
        }

        $index = [];
        foreach (['external', 'internal'] as $groupKey) {
            $group = $assessments[$groupKey] ?? [];
            if (!is_array($group)) {
                continue;
            }
            foreach ($group as $assessment) {
                if (!is_array($assessment)) {
                    continue;
                }
                $questionnaires = $assessment['questionnaires'] ?? [];
                if (!is_array($questionnaires)) {
                    continue;
                }
                foreach ($questionnaires as $questionnaire) {
                    if (!is_array($questionnaire)) {
                        continue;
                    }
                    $instances = $questionnaire['instances'] ?? [];
                    if (!is_array($instances)) {
                        continue;
                    }
                    foreach ($instances as $instance) {
                        if (!is_array($instance)) {
                            continue;
                        }
                        $qaList = $instance['qa'] ?? [];
                        if (!is_array($qaList)) {
                            continue;
                        }
                        foreach ($qaList as $qa) {
                            if (!is_array($qa)) {
                                continue;
                            }
                            $question = trim((string) ($qa['question'] ?? ''));
                            $answer = self::cleanValue(normalizeDisplayValue($qa['answer'] ?? ''));
                            if ($question === '' || $answer === '') {
                                continue;
                            }
                            $index[] = [
                                'question' => $question,
                                'answer' => $answer,
                                'question_lc' => strtolower($question),
                            ];
                        }
                    }
                }
            }
        }

        return $index;
    }

    /**
     * Return the first answered QA whose question contains any of the needles.
     *
     * @param list<array{question: string, answer: string, question_lc: string}> $qaIndex
     * @param list<string> $needles
     * @param bool $requireSubstantial Prefer answers with letters / URL / path content
     */
    private static function qaAnswer(array $qaIndex, array $needles, bool $requireSubstantial = false): string
    {
        if ($qaIndex === [] || $needles === []) {
            return '';
        }

        $needlesLc = array_map(static fn (string $n): string => strtolower(trim($n)), $needles);
        $needlesLc = array_values(array_filter($needlesLc, static fn (string $n): bool => $n !== ''));

        // Try longer needles first so specific phrases win over short codes.
        usort($needlesLc, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        foreach ($needlesLc as $needle) {
            foreach ($qaIndex as $item) {
                if (!str_contains($item['question_lc'], $needle)) {
                    continue;
                }
                $answer = self::cleanValue($item['answer']);
                if ($answer === '') {
                    continue;
                }
                if ($requireSubstantial && !self::isSubstantialAnswer($answer)) {
                    continue;
                }

                return $answer;
            }
        }

        return '';
    }

    private static function isSubstantialAnswer(string $answer): bool
    {
        if (strlen($answer) >= 8) {
            return true;
        }

        return (bool) preg_match('/[A-Za-z]{3,}/', $answer)
            || str_contains($answer, 'http')
            || str_contains($answer, '/');
    }

    private static function containsHint(string $haystack, string $needle): bool
    {
        return $haystack !== '' && str_contains(strtolower($haystack), strtolower($needle));
    }
}
