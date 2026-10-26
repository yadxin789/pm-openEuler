<?php

namespace Database\Seeders;

use App\Models\AiDocTemplate;
use App\Models\AiSkill;
use App\Models\AiSetting;
use Illuminate\Database\Seeder;

class AiSeeder extends Seeder
{
    public function run(): void
    {
        // 初始化 AI 配置（单行）
        if (!AiSetting::find(1)) {
            AiSetting::create([
                'provider' => 'deepseek',
                'base_url' => 'https://api.deepseek.com/v1',
                'model' => 'deepseek-chat',
                'temperature' => 0.7,
                'max_tokens' => 2000,
                'enabled' => false,
            ]);
        }

        // 默认文档模板
        $templates = [
            [
                'name' => '项目立项报告',
                'category' => '项目管理',
                'description' => '依据项目信息生成立项报告，含背景、目标、交付物、里程碑、风险等。',
                'template_path' => 'ai_docs/templates/project-proposal.md',
                'example_path' => null,
                'support_files' => [],
                'prompt_guide' => "用自然语言提供：项目名称、代码、产品名称/型号、版本、发起人、起止周期、建设内容与交付物、参与部门与分工、里程碑计划、风险。\n"
                    . "如用户提及系统已有项目，可调用 QueryProjects / QueryProjectDetail 工具获取真实数据填入。\n"
                    . "模板占位符（{{...}}）必须全部填充，未提供的数据保留 {{占位}} 并标注【待补充】。",
            ],
            [
                'name' => '工作周报',
                'category' => '汇报',
                'description' => '依据某部门/团队的项目与任务数据生成周报，含完成事项、项目进展、问题风险、下周计划。',
                'template_path' => 'ai_docs/templates/weekly-report.md',
                'example_path' => 'ai_docs/templates/weekly-report-example.md',
                'support_files' => [],
                'prompt_guide' => "用自然语言提供：部门/团队、周次与时间范围。\n"
                    . "优先调用 QueryTasks / QueryProjects 获取该时间段内真实的任务与项目进展填入。\n"
                    . "范例文件展示周报写法，供参考格式与详略。",
            ],
            [
                'name' => '会议纪要',
                'category' => '会议',
                'description' => '依据会议描述生成结构化会议纪要，含议题讨论、结论、待办事项、遗留问题。',
                'template_path' => 'ai_docs/templates/meeting-minutes.md',
                'example_path' => null,
                'support_files' => [],
                'prompt_guide' => "用自然语言提供：会议主题、时间、方式、主持人、参会人员、议程、各议题讨论要点与结论、待办事项及负责人与截止时间。\n"
                    . "如涉及知识库制度可调用 SearchKnowledgeBase 引用正确口径。",
            ],
            [
                'name' => '需求规格说明书',
                'category' => '研发',
                'description' => '依据需求描述生成需求规格说明书，含功能/非功能需求、接口、约束。',
                'template_path' => 'ai_docs/templates/requirements-doc.md',
                'example_path' => null,
                'support_files' => [],
                'prompt_guide' => "用自然语言提供：系统/模块名称、需求背景、功能清单（名称/描述/优先级/验收标准）、非功能需求、数据来源与接口、约束假设。\n"
                    . "可调用 SearchKnowledgeBase 引用公司制度/规范。",
            ],
            [
                'name' => '项目验收报告',
                'category' => '项目管理',
                'description' => '依据项目数据生成验收报告，含交付物核对、任务完成情况、验收结论、遗留问题。',
                'template_path' => 'ai_docs/templates/acceptance-report.md',
                'example_path' => null,
                'support_files' => [],
                'prompt_guide' => "用自然语言提供：项目名称/代码、验收日期/方式/组织、验收范围与依据、交付物清单、存在问题、验收结论。\n"
                    . "优先调用 QueryProjects / QueryProjectDetail 获取项目与任务完成率真实数据。",
            ],
        ];

        foreach ($templates as $t) {
            AiDocTemplate::updateOrCreate(['name' => $t['name']], $t);
        }

        // PM 专业服务 Skill 目录（能力注册表）
        $skills = [
            ['name' => 'PM 数据问答', 'slug' => 'pm-qa', 'type' => 'agent', 'description' => '对项目、任务、用户、部门、知识库等 PM 数据进行智能问答。', 'config' => ['tools' => ['QueryPmOverview', 'QueryProjects', 'QueryTasks', 'QueryUsers', 'QueryDepartments', 'QueryKnowledge']]],
            ['name' => '项目数据洞察', 'slug' => 'pm-insight', 'type' => 'tool', 'description' => '查询项目进度、逾期、任务完成情况，辅助管理决策。', 'config' => ['tools' => ['QueryPmOverview', 'QueryProjects', 'QueryProjectDetail', 'QueryTasks']]],
            ['name' => '知识库检索', 'slug' => 'knowledge-retrieval', 'type' => 'tool', 'description' => '检索知识库制度、流程、规范文档。', 'config' => ['tools' => ['QueryKnowledge', 'SearchKnowledgeBase']]],
            ['name' => '文档生成', 'slug' => 'doc-generator', 'type' => 'doc-generator', 'description' => '用自然语言描述数据/参数，依据模板、范例、知识库自动生成项目立项报告、周报、会议纪要、需求规格、验收报告等文档。', 'config' => ['tools' => ['GetDocTemplate', 'SearchKnowledgeBase', 'QueryProjects', 'QueryTasks']]],
            ['name' => '文件检索', 'slug' => 'file-retrieval', 'type' => 'tool', 'description' => '查询任务交付文件、资源文件及其审核状态。', 'config' => ['tools' => ['QueryFiles']]],
            ['name' => '数据变更助理', 'slug' => 'data-change', 'type' => 'agent', 'description' => '按用户指令提交任务 / 审核 / 知识库 / 支援文件的变更提案。AI 只负责起草提案，数据需你在「AI 授权中心」确认后才会真正变更。', 'config' => ['tools' => ['ProposeTaskChange', 'ProposeTaskAudit', 'ProposeKnowledgeChange', 'ProposeKnowledgeDocChange', 'ProposeDepartmentFileChange', 'ListMyProposals', 'QueryProjects', 'QueryProjectDetail', 'QueryTasks', 'QueryKnowledge', 'QueryFiles']]],
        ];

        foreach ($skills as $s) {
            AiSkill::updateOrCreate(['slug' => $s['slug']], $s);
        }
    }
}
