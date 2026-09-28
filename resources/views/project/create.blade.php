@extends('layouts.app')

@section('title', '创建项目')

@section('content')
<h1 class="mb-4">创建项目</h1>

<form method="POST" action="{{ route('project.store') }}" id="project-form" onsubmit="return prepareSubmit()">
    @csrf
    <div class="row g-3">
        <div class="col-md-2">
            <label for="name" class="form-label">项目名称 *</label>
            <input type="text" name="name" id="name" class="form-control" required value="{{ old('name') }}">
        </div>
        <div class="col-md-1">
            <label for="code" class="form-label">项目编码</label>
            <input type="text" name="code" id="code" class="form-control" placeholder="留空自动生成" value="{{ old('code') }}">
            <small class="text-muted">留空将自动生成唯一编码</small>
        </div>
        <div class="col-md-1">
            <label for="version" class="form-label">版本号</label>
            <input type="text" name="version" id="version" class="form-control" value="{{ old('version', 'V1.0') }}">
        </div>
        <div class="col-md-2">
            <label for="product_name" class="form-label">产品名称 *</label>
            <input type="text" name="product_name" id="product_name" class="form-control" required value="{{ old('product_name') }}">
        </div>
        <div class="col-md-2">
            <label for="product_model" class="form-label">产品型号 *</label>
            <input type="text" name="product_model" id="product_model" class="form-control" required value="{{ old('product_model') }}">
        </div>
        <div class="col-md-2">
            <label for="start_date" class="form-label">开始日期 *</label>
            <input type="date" name="start_date" id="start_date" class="form-control" required onchange="calculateTaskDates()" value="{{ old('start_date') }}">
        </div>
    </div>

    <!-- 导入模板 -->
    <div class="row g-3 mt-3">
        <div class="col-md-4">
            <label for="template_id" class="form-label">导入模板</label>
            <select name="template_id" id="template_id" class="form-control">
                <option value="">选择模板（可选）</option>
                @forelse($templates as $template)
                <option value="{{ $template->id }}">{{ $template->name }}</option>
                @empty
                <option value="" disabled>暂无模板，请先创建模板</option>
                @endforelse
            </select>
        </div>
        <div class="col-md-2 d-flex align-items-end">
            <button type="button" class="btn btn-success w-full" onclick="loadTemplate()" {{ $templates->isEmpty() ? 'disabled' : '' }}>导入模板配置</button>
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
    @endphp
    <div class="mt-4">
        <h4>选择参与部门 <small class="text-muted">（勾选需要参与项目的部门）</small></h4>
        <div class="card">
            <div class="card-body">
                <div class="d-flex flex-wrap gap-3" id="dept-checkbox-container">
                    @foreach($deptMap as $deptId => $department)
                    @php
                        $oldSelectedDepts = old('selected_depts');
                        $isChecked = $oldSelectedDepts === null ? true : in_array($deptId, $oldSelectedDepts);
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
                    <div class="col-md-2 dept-days-wrapper" data-dept-id="{{ $deptId }}">
                        <label class="form-label">{{ $department->name ?? '部门'.$deptId }}</label>
                        <div class="input-group">
                            <input type="number" name="dept_days[{{ $deptId }}]" 
                                class="form-control dept-days-input" 
                                value="{{ old('dept_days.'.$deptId, 5) }}" min="1" data-dept-id="{{ $deptId }}" 
                                onchange="calculateTaskDates()">
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
                            <th style="width: 15%">任务类型</th>
                            <th style="width: 10%">部门</th>
                            <th style="width: 6%" class="text-center">选中</th>
                            <th style="width: 12%">起始日期</th>
                            <th style="width: 12%">截止日期</th>
                            <th style="width: 18%">执行人</th>
                            <th style="width: 18%">审核人</th>
                        </tr>
                    </thead>
                    <tbody id="task-body">
                        @foreach($taskTypes as $index => $type)
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
                                    checked 
                                    onchange="onTaskEnabledChange()">
                            </td>
                            <td>
                                <input type="date" name="tasks[{{ $index }}][start_date]" 
                                    class="form-control form-control-sm task-start-date" readonly>
                            </td>
                            <td>
                                <input type="date" name="tasks[{{ $index }}][end_date]" 
                                    class="form-control form-control-sm task-end-date" readonly>
                            </td>
                            <td>
                                <select name="tasks[{{ $index }}][executor_id]" 
                                    class="form-control form-select-sm executor-select" data-dept-id="{{ $type->department_id }}">
                                    <option value="">选择执行人</option>
                                </select>
                            </td>
                            <td>
                                <select name="tasks[{{ $index }}][auditor_id]" 
                                    class="form-control form-select-sm auditor-select" data-dept-id="{{ $type->department_id }}">
                                    <option value="">选择审核人</option>
                                </select>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <!-- 项目统计 -->
        <div class="mt-4 card">
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-2">
                        <label class="form-label">起始日期</label>
                        <input type="date" id="stats-start-date" class="form-control" readonly>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">任务总数量</label>
                        <input type="number" id="stats-task-count" class="form-control" readonly>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">部门数量</label>
                        <input type="number" id="stats-dept-count" class="form-control" readonly>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">项目总天数</label>
                        <input type="number" id="stats-total-days" class="form-control" readonly>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">项目截止日期</label>
                        <input type="date" id="stats-end-date" class="form-control" readonly>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <button type="submit" class="btn btn-primary mt-4">创建项目</button>
</form>

<script>
// 后端回显数据：用于验证失败后恢复选中的执行人和审核人
@php
    $oldTasks = old('tasks');
    $oldTasksCollection = $oldTasks ? collect($oldTasks) : collect();
    $oldExecutorIds = $oldTasksCollection->pluck('executor_id')->toArray();
    $oldAuditorIds = $oldTasksCollection->pluck('auditor_id')->toArray();
@endphp
const oldTaskExecutorIds = @json($oldExecutorIds);
const oldTaskAuditorIds = @json($oldAuditorIds);
// 所有部门的排序顺序（用于部门间串行计算）
const deptOrder = @json(array_keys($deptMap));

/**
 * 提交前：禁用隐藏任务行的表单字段，避免它们被提交和验证
 * 同时进行前端校验
 */
function prepareSubmit() {
    // 获取当前选中的部门
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

    // ====== 前端校验：任何缺失都不允许提交 ======
    let hasError = false;
    const errorList = [];

    if (selectedDepts.length === 0) {
        errorList.push('请至少选择一个部门');
        hasError = true;
    }

    // 逐个检查所有【可见且启用】任务行
    document.querySelectorAll('.task-row').forEach(row => {
        const deptId = row.dataset.deptId;
        const taskIndex = row.dataset.taskIndex;
        if (!selectedDepts.includes(String(deptId))) return; // 跳过未选部门的任务
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

    // 新增：检查基础字段（多重保险）
    if (!document.getElementById('name').value.trim()) {
        errorList.push('请填写项目名称');
        hasError = true;
    }
    if (!document.getElementById('product_name').value.trim()) {
        errorList.push('请填写产品名称');
        hasError = true;
    }
    if (!document.getElementById('product_model').value.trim()) {
        errorList.push('请填写产品型号');
        hasError = true;
    }
    if (!document.getElementById('start_date').value) {
        errorList.push('请选择开始日期');
        hasError = true;
    }

    const errorMsg = errorList.join('\n');

    if (hasError) {
        showGlobalModal('danger', '项目创建失败', errorMsg);
        return false;  // 绝对阻止提交
    }

    // 校验通过，继续提交表单
    document.getElementById('project-form').submit();
    return false;
}

document.addEventListener('DOMContentLoaded', function() {
    let loadedCount = 0;
    const totalLoads = deptOrder.filter(Boolean).length * 2; // 每个部门加载执行人和审核人两次

    function checkAllLoaded() {
        loadedCount++;
        if (loadedCount >= totalLoads) {
            // 所有人员数据加载完毕后，恢复 old 选中值
            restoreOldSelections();
            calculateTaskDates();
        }
    }

    // 加载所有部门的人员数据
    deptOrder.forEach(deptId => {
        if (!deptId) return;

        fetch(`/project/get-designers/${deptId}`)
            .then(res => res.json())
            .then(data => {
                document.querySelectorAll(`.executor-select[data-dept-id="${deptId}"]`).forEach(select => {
                    data.forEach(user => {
                        const option = document.createElement('option');
                        option.value = user.id;
                        option.textContent = user.name;
                        select.appendChild(option);
                    });
                });
                checkAllLoaded();
            })
            .catch(() => checkAllLoaded());

        fetch(`/project/get-managers/${deptId}`)
            .then(res => res.json())
            .then(data => {
                document.querySelectorAll(`.auditor-select[data-dept-id="${deptId}"]`).forEach(select => {
                    data.forEach(user => {
                        const option = document.createElement('option');
                        option.value = user.id;
                        option.textContent = user.name;
                        select.appendChild(option);
                    });
                });
                checkAllLoaded();
            })
            .catch(() => checkAllLoaded());
    });

    // 如果没有需要加载的数据（空部门），直接执行
    if (totalLoads === 0) {
        calculateTaskDates();
    }
});

/**
 * 恢复后端回显的执行人和审核人选择
 */
function restoreOldSelections() {
    document.querySelectorAll('.task-row').forEach((row, index) => {
        const executorSelect = row.querySelector('.executor-select');
        const auditorSelect = row.querySelector('.auditor-select');

        if (executorSelect && oldTaskExecutorIds[index]) {
            executorSelect.value = oldTaskExecutorIds[index];
        }
        if (auditorSelect && oldTaskAuditorIds[index]) {
            auditorSelect.value = oldTaskAuditorIds[index];
        }
    });
}

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

    calculateTaskDates();
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
 * 导入模板配置（导入部门选择、天数、执行人和审核人）
 */
function loadTemplate() {
    const templateId = document.getElementById('template_id').value;

    if (!templateId) {
        alert('请先选择一个模板');
        return;
    }

    fetch(`/project/get-template/${templateId}`)
        .then(res => res.json())
        .then(data => {
            // 1. 恢复部门选择
            if (data.selected_depts) {
                // 先取消所有部门选择
                document.querySelectorAll('.dept-checkbox').forEach(cb => {
                    cb.checked = false;
                });
                
                // 勾选模板中选择的部门
                data.selected_depts.forEach(deptId => {
                    const checkbox = document.getElementById('dept-check-' + deptId);
                    if (checkbox) {
                        checkbox.checked = true;
                    }
                });
            }

            // 2. 恢复部门天数配置
            if (data.dept_days) {
                Object.keys(data.dept_days).forEach(deptId => {
                    const daysInput = document.querySelector(`.dept-days-input[data-dept-id="${deptId}"]`);
                    if (daysInput) {
                        daysInput.value = data.dept_days[deptId];
                    }
                });
            }

            // 3. 恢复模板的任务顺序、启用状态、执行人和审核人（一切与模板保持一致）
            if (data.tasks && data.tasks.length > 0) {
                // 3.1 按模板保存的排序顺序重排任务行（data.tasks 已按 sort_order 升序返回）
                const allRows = Array.from(document.querySelectorAll('.task-row'));
                const rowByTypeId = {};
                allRows.forEach(row => {
                    rowByTypeId[String(row.dataset.taskTypeId)] = row;
                });

                const orderedRows = [];
                const matchedTypeIds = {};
                data.tasks.forEach(templateTask => {
                    const row = rowByTypeId[String(templateTask.task_type_id)];
                    if (row) {
                        orderedRows.push(row);
                        matchedTypeIds[String(templateTask.task_type_id)] = true;
                    }
                });
                // 模板中不存在的任务行排在末尾，保持原有相对顺序
                allRows.forEach(row => {
                    if (!matchedTypeIds[String(row.dataset.taskTypeId)]) {
                        orderedRows.push(row);
                    }
                });
                const taskBody = document.getElementById('task-body');
                orderedRows.forEach(row => taskBody.appendChild(row));

                // 3.2 先禁用所有任务，再按模板勾选并填入执行人、审核人
                document.querySelectorAll('.task-enabled-checkbox').forEach(cb => {
                    cb.checked = false;
                });

                data.tasks.forEach(templateTask => {
                    const row = rowByTypeId[String(templateTask.task_type_id)];
                    if (!row) return;

                    const checkbox = row.querySelector('.task-enabled-checkbox');
                    if (checkbox) {
                        checkbox.checked = true;
                    }

                    const executorSelect = row.querySelector('.executor-select');
                    if (executorSelect && templateTask.executor_id) {
                        executorSelect.value = templateTask.executor_id;
                    }

                    const auditorSelect = row.querySelector('.auditor-select');
                    if (auditorSelect && templateTask.auditor_id) {
                        auditorSelect.value = templateTask.auditor_id;
                    }
                });
            }

            // 4. 刷新显示和计算
            onDeptSelectionChange();
            calculateTaskDates();
            alert('模板配置已导入（部门选择、天数、任务顺序、执行人和审核人）');
        })
        .catch(error => {
            console.error('加载模板失败:', error);
            alert('加载模板失败，请重试');
        });
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
    calculateTaskDates();
}

/**
 * 计算任务日期：部门间串行，部门内并联同步
 * 只计算选中部门且启用的任务
 */
function calculateTaskDates() {
    const startDateInput = document.getElementById('start_date');
    if (!startDateInput.value) return;

    const startDate = new Date(startDateInput.value);
    let deptStartDate = new Date(startDate);
    const selectedDepts = getSelectedDepts();

    // 读取每个部门的天数配置
    const deptDays = {};
    document.querySelectorAll('.dept-days-input').forEach(input => {
        const deptId = input.dataset.deptId;
        deptDays[deptId] = parseInt(input.value) || 1;
    });

    let projectEndDate = null;
    let totalDays = 0;
    let visibleTaskCount = 0;

    // 按部门顺序串行计算，但只处理选中的部门
    deptOrder.forEach(deptId => {
        // 跳过未选中的部门
        if (!selectedDepts.includes(String(deptId))) return;

        const days = deptDays[deptId] || 1;

        // 计算该部门的截止日期
        const deptEndDate = new Date(deptStartDate);
        deptEndDate.setDate(deptEndDate.getDate() + days - 1);

        // 该部门内所有【启用】的任务并联同步：起止日期相同
        document.querySelectorAll(`.task-row[data-dept-id="${deptId}"]`).forEach(row => {
            const taskIndex = row.dataset.taskIndex;
            if (!isTaskEnabled(taskIndex)) return; // 跳过未启用的任务

            const startInput = row.querySelector('.task-start-date');
            const endInput = row.querySelector('.task-end-date');

            if (startInput) {
                startInput.value = deptStartDate.toISOString().split('T')[0];
            }
            if (endInput) {
                endInput.value = deptEndDate.toISOString().split('T')[0];
            }
            visibleTaskCount++;
        });

        projectEndDate = deptEndDate;
        totalDays += days;

        // 下一个部门从当前部门结束后一天开始
        deptStartDate = new Date(deptEndDate);
        deptStartDate.setDate(deptStartDate.getDate() + 1);
    });

    // 更新统计信息
    document.getElementById('stats-start-date').value = startDate.toISOString().split('T')[0];
    document.getElementById('stats-task-count').value = visibleTaskCount;
    document.getElementById('stats-dept-count').value = selectedDepts.length;
    document.getElementById('stats-total-days').value = totalDays;
    document.getElementById('stats-end-date').value = projectEndDate ? projectEndDate.toISOString().split('T')[0] : '';
}
</script>
@endsection