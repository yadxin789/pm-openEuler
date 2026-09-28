@extends('layouts.app')

@section('title', '编辑项目模板')

@section('content')
<h1 class="mb-4">编辑项目模板</h1>

<form method="POST" action="{{ route('project-template.update', $projectTemplate) }}" id="template-form">
    @csrf
    @method('PUT')
    
    <!-- 模板基本信息 -->
    <div class="row g-3">
        <div class="col-md-6">
            <label for="name" class="form-label">模板名称 *</label>
            <input type="text" name="name" id="name" class="form-control" required value="{{ old('name', $projectTemplate->name) }}">
        </div>
        <div class="col-md-6">
            <label for="description" class="form-label">模板描述</label>
            <input type="text" name="description" id="description" class="form-control" value="{{ old('description', $projectTemplate->description ?? '') }}">
        </div>
    </div>

    <!-- 选择参与部门（多选） -->
    @php
        $deptMap = [];
        foreach ($taskTypes as $type) {
            if ($type->department_id && !isset($deptMap[$type->department_id])) {
                $deptMap[$type->department_id] = $type->department;
            }
        }
        
        // 获取模板已保存的部门选择
        $savedDeptIds = $projectTemplate->templateTasks->pluck('department_id')->unique()->toArray();
    @endphp
    <div class="mt-4">
        <h4>选择参与部门 <small class="text-muted">（勾选需要参与项目的部门）</small></h4>
        <div class="card">
            <div class="card-body">
                <div class="d-flex flex-wrap gap-3" id="dept-checkbox-container">
                    @foreach($deptMap as $deptId => $department)
                    @php
                        $oldSelectedDepts = old('selected_depts');
                        $isChecked = $oldSelectedDepts !== null 
                            ? in_array($deptId, $oldSelectedDepts) 
                            : in_array($deptId, $savedDeptIds);
                    @endphp
                    <div class="form-check form-check-inline">
                        <input class="form-check-input dept-checkbox" type="checkbox" 
                            id="dept-check-{{ $deptId }}" name="selected_depts[]" value="{{ $deptId }}" 
                            {{ $isChecked ? 'checked' : '' }} onchange="onDeptSelectionChange()">
                        <label class="form-check-label" for="dept-check-{{ $deptId }}">
                            {{ $department->name ?? '部门'.$deptId }}
                        </label>
                    </div>
                    @endforeach
                </div>
                <div class="mt-2">
                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="selectAllDepts()">全选</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary ms-1" onclick="deselectAllDepts()">取消全选</button>
                </div>
            </div>
        </div>
    </div>

    <!-- 部门天数配置（部门间串行，部门内并联） -->
    <div class="mt-4">
        <h4>部门天数配置</h4>
        <div class="card">
            <div class="card-body">
                <p class="text-muted small mb-2">设置每个部门的天数。部门间串行执行，部门内任务并联同步执行（同部门任务起止日期相同）</p>
                <div class="row g-3" id="dept-days-container">
                    @foreach($deptMap as $deptId => $department)
                    @php
                        // 获取模板已保存的天数
                        $savedTask = $projectTemplate->templateTasks->where('department_id', $deptId)->first();
                        $savedDays = $savedTask ? $savedTask->days : 5;
                    @endphp
                    <div class="col-md-2 dept-days-wrapper" data-dept-id="{{ $deptId }}">
                        <label class="form-label">{{ $department->name ?? '部门'.$deptId }}</label>
                        <div class="input-group">
                            <input type="number" name="dept_days[{{ $deptId }}]" 
                                class="form-control dept-days-input" 
                                value="{{ old('dept_days.'.$deptId, $savedDays) }}" min="1" data-dept-id="{{ $deptId }}">
                            <span class="input-group-text">天</span>
                        </div>
                    </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>

    <!-- 任务配置（部门内并联同步） -->
    <div class="mt-4">
        <h4>任务配置 <small class="text-muted">（部门内任务并联同步）</small></h4>
        <div class="card">
            <div class="card-body">
                <table class="table table-bordered table-striped" id="task-table">
                    <thead>
                        <tr>
                            <th style="width: 18%">任务类型</th>
                            <th style="width: 12%">部门</th>
                            <th style="width: 8%" class="text-center">选中</th>
                            <th style="width: 28%">执行人</th>
                            <th style="width: 28%">审核人</th>
                        </tr>
                    </thead>
                    <tbody id="task-body">
                        @foreach($taskTypes as $index => $type)
                        @php
                            // 获取模板已保存的任务配置
                            $savedTask = $projectTemplate->templateTasks->where('task_type_id', $type->id)->first();
                            $savedExecutorId = $savedTask ? $savedTask->executor_id : null;
                            $savedAuditorId = $savedTask ? $savedTask->auditor_id : null;
                            $isEnabled = $savedTask ? true : false;
                        @endphp
                        <tr class="task-row" data-task-index="{{ $index }}" data-dept-id="{{ $type->department_id }}" data-task-type-id="{{ $type->id }}">
                            <td>
                                <input type="hidden" name="tasks[{{ $index }}][task_type_id]" value="{{ $type->id }}">
                                {{ $type->name }}
                            </td>
                            <td>
                                <input type="hidden" name="tasks[{{ $index }}][department_id]" value="{{ $type->department_id }}">
                                {{ $type->department->name ?? '' }}
                            </td>
                            <td class="text-center">
                                <input type="checkbox" 
                                    name="tasks[{{ $index }}][enabled]" 
                                    value="1" 
                                    class="form-check-input task-enabled-checkbox" 
                                    data-task-index="{{ $index }}"
                                    {{ $isEnabled ? 'checked' : '' }} 
                                    onchange="onTaskEnabledChange()">
                            </td>
                            <td>
                                <select name="tasks[{{ $index }}][executor_id]" 
                                    class="form-control form-select-sm executor-select" data-dept-id="{{ $type->department_id }}">
                                    <option value="">选择执行人</option>
                                    @foreach($executors as $user)
                                    <option value="{{ $user->id }}" 
                                        data-department="{{ $user->department_id }}"
                                        {{ $savedExecutorId == $user->id ? 'selected' : '' }}>
                                        {{ $user->name }} ({{ $user->department->name ?? '-' }} - {{ $user->role->name ?? '-' }})
                                    </option>
                                    @endforeach
                                </select>
                            </td>
                            <td>
                                <select name="tasks[{{ $index }}][auditor_id]" 
                                    class="form-control form-select-sm auditor-select" data-dept-id="{{ $type->department_id }}">
                                    <option value="">选择审核人</option>
                                    @foreach($auditors as $user)
                                    <option value="{{ $user->id }}" 
                                        data-department="{{ $user->department_id }}"
                                        {{ $savedAuditorId == $user->id ? 'selected' : '' }}>
                                        {{ $user->name }} ({{ $user->department->name ?? '-' }} - {{ $user->role->name ?? '-' }})
                                    </option>
                                    @endforeach
                                </select>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <!-- 模板统计 -->
        <div class="mt-4 card">
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">任务总数量</label>
                        <input type="number" id="stats-task-count" class="form-control" readonly>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">部门数量</label>
                        <input type="number" id="stats-dept-count" class="form-control" readonly>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">项目总天数</label>
                        <input type="number" id="stats-total-days" class="form-control" readonly>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="d-flex justify-content-between mt-4">
        <a href="{{ route('project-template.index') }}" class="btn btn-secondary">返回列表</a>
        <button type="submit" class="btn btn-primary">
            <i class="fa fa-save me-1"></i>保存模板
        </button>
    </div>
</form>

<script>
// 所有部门的排序顺序（用于部门间串行计算）
const deptOrder = @json(array_keys($deptMap));

/**
 * 提交前：禁用隐藏任务行的表单字段，避免它们被提交和验证
 */
function prepareSubmit() {
    const selectedDepts = getSelectedDepts();
    
    // 移除隐藏行和未启用任务行的 name 属性（不提交），确保可见行有 name
    document.querySelectorAll('.task-row').forEach(row => {
        const deptId = row.dataset.deptId;
        const taskIndex = row.dataset.taskIndex;
        const isVisible = selectedDepts.includes(String(deptId));
        const isEnabled = isTaskEnabled(taskIndex);

        row.querySelectorAll('input, select').forEach(el => {
            if (!isVisible || !isEnabled) {
                if (el.name) {
                    el.setAttribute('data-disabled-name', el.name);
                    el.removeAttribute('name');
                }
            } else {
                if (el.hasAttribute('data-disabled-name')) {
                    el.name = el.getAttribute('data-disabled-name');
                    el.removeAttribute('data-disabled-name');
                }
            }
        });
    });

    // ====== 前端校验 ======
    let hasError = false;
    const errorList = [];

    if (selectedDepts.length === 0) {
        errorList.push('请至少选择一个部门');
        hasError = true;
    }

    // 逐个检查所有【可见且启用】的任务行
    document.querySelectorAll('.task-row').forEach(row => {
        const deptId = row.dataset.deptId;
        const taskIndex = row.dataset.taskIndex;
        if (!selectedDepts.includes(String(deptId))) return;
        if (!isTaskEnabled(taskIndex)) return; // 跳过未启用的任务

        const taskName = row.querySelector('td:first-child')?.innerText?.trim() || '未命名任务';
        const deptName = row.querySelector('td:nth-child(2)')?.innerText?.trim() || '';
        const label = deptName ? `【${deptName}】${taskName}` : taskName;

        const executor = row.querySelector('.executor-select');
        const auditor = row.querySelector('.auditor-select');

        if (!executor || executor.value === '' || executor.value === null || executor.value === undefined) {
            errorList.push(`任务「${label}」未选择执行人`);
            hasError = true;
        }
        if (!auditor || auditor.value === '' || auditor.value === null || auditor.value === undefined) {
            errorList.push(`任务「${label}」未选择审核人`);
            hasError = true;
        }
    });

    if (!document.getElementById('name').value.trim()) {
        errorList.push('请填写模板名称');
        hasError = true;
    }

    const errorMsg = errorList.join('\n');

    if (hasError) {
        showGlobalModal('danger', '模板保存失败', errorMsg);
        return false;
    }

    document.getElementById('template-form').submit();
    return false;
}

/**
 * 判断任务是否启用
 */
function isTaskEnabled(taskIndex) {
    const checkbox = document.querySelector(`.task-enabled-checkbox[data-task-index="${taskIndex}"]`);
    return checkbox && checkbox.checked;
}

/**
 * 任务启用/禁用变化时的处理
 */
function onTaskEnabledChange() {
    calculateStats();
}

document.addEventListener('DOMContentLoaded', function() {
    // 根据任务类型所属部门自动筛选执行人和审核人
    const rows = document.querySelectorAll('#task-table tbody tr');
    
    rows.forEach(function(row, index) {
        const departmentCell = row.querySelector('td:nth-child(2)');
        const executorSelect = row.querySelector('.executor-select');
        const auditorSelect = row.querySelector('.auditor-select');
        
        const departmentName = departmentCell.textContent.trim();
        
        if (departmentName && departmentName !== '-') {
            filterOptionsByDepartment(executorSelect, departmentName);
            filterOptionsByDepartment(auditorSelect, departmentName);
        }
    });
    
    function filterOptionsByDepartment(select, departmentName) {
        const options = select.querySelectorAll('option');
        options.forEach(function(option) {
            if (option.value === '') return;
            
            const optionText = option.textContent;
            if (optionText.includes(departmentName)) {
                option.style.display = '';
            } else {
                option.style.display = 'none';
            }
        });
    }

    // 初始化显示
    onDeptSelectionChange();
});

/**
 * 获取当前选中的部门 ID 列表
 */
function getSelectedDepts() {
    const selected = [];
    document.querySelectorAll('.dept-checkbox:checked').forEach(cb => {
        selected.push(cb.value);
    });
    return selected;
}

/**
 * 部门复选框变化时：显示/隐藏对应的天数配置和任务行
 */
function onDeptSelectionChange() {
    const selectedDepts = getSelectedDepts();

    // 显示/隐藏部门天数配置
    document.querySelectorAll('.dept-days-wrapper').forEach(wrapper => {
        const deptId = wrapper.dataset.deptId;
        wrapper.style.display = selectedDepts.includes(String(deptId)) ? '' : 'none';
    });

    // 显示/隐藏任务行
    document.querySelectorAll('.task-row').forEach(row => {
        const deptId = row.dataset.deptId;
        row.style.display = selectedDepts.includes(String(deptId)) ? '' : 'none';
    });

    calculateStats();
}

/**
 * 全选部门
 */
function selectAllDepts() {
    document.querySelectorAll('.dept-checkbox').forEach(cb => {
        cb.checked = true;
    });
    onDeptSelectionChange();
}

/**
 * 取消全选部门
 */
function deselectAllDepts() {
    document.querySelectorAll('.dept-checkbox').forEach(cb => {
        cb.checked = false;
    });
    onDeptSelectionChange();
}

/**
 * 计算模板统计信息
 */
function calculateStats() {
    const selectedDepts = getSelectedDepts();

    // 读取每个部门的天数配置
    const deptDays = {};
    document.querySelectorAll('.dept-days-input').forEach(input => {
        const deptId = input.dataset.deptId;
        deptDays[deptId] = parseInt(input.value) || 1;
    });

    let totalDays = 0;
    let visibleTaskCount = 0;

    // 按部门顺序串行计算，但只处理选中的部门
    deptOrder.forEach(deptId => {
        // 跳过未选中的部门
        if (!selectedDepts.includes(String(deptId))) return;

        const days = deptDays[deptId] || 1;

        // 统计该部门内【启用】的任务数量
        document.querySelectorAll(`.task-row[data-dept-id="${deptId}"]`).forEach(row => {
            const taskIndex = row.dataset.taskIndex;
            if (isTaskEnabled(taskIndex)) {
                visibleTaskCount++;
            }
        });

        totalDays += days;
    });

    // 更新统计信息
    document.getElementById('stats-task-count').value = visibleTaskCount;
    document.getElementById('stats-dept-count').value = selectedDepts.length;
    document.getElementById('stats-total-days').value = totalDays;
}
</script>
@endsection