<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>文件预览 - {{ $file->original_name }}</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background-color: #f5f5f5;
            overflow: hidden;
        }
        .preview-header {
            background-color: #fff;
            padding: 15px 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1000;
        }
        .preview-title {
            font-size: 16px;
            font-weight: 500;
            color: #333;
        }
        .preview-actions {
            display: flex;
            gap: 10px;
        }
        .btn {
            padding: 8px 16px;
            border-radius: 4px;
            text-decoration: none;
            font-size: 14px;
            cursor: pointer;
            transition: background-color 0.3s;
        }
        .btn-download {
            background-color: #3498db;
            color: white;
            border: none;
        }
        .btn-download:hover {
            background-color: #2980b9;
        }
        .btn-close {
            background-color: #e74c3c;
            color: white;
            border: none;
        }
        .btn-close:hover {
            background-color: #c0392b;
        }
        .preview-container {
            position: fixed;
            top: 60px;
            left: 0;
            right: 0;
            bottom: 0;
            overflow: auto;
            background-color: #f5f5f5;
        }
        .preview-content {
            width: 100%;
            height: calc(100vh - 80px);
            margin: 0;
            padding: 0;
        }
        /* PDF 预览 */
        #pdf-viewer {
            width: 100%;
            height: 100%;
            border: none;
            display: block;
        }
        #pdf-viewer iframe {
            width: 100%;
            height: 100%;
            border: none;
        }
        /* Word 文档预览 */
        #word-viewer {
            background-color: white;
            padding: 40px;
            max-width: 900px;
            margin: 0 auto;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        #word-viewer .word-content {
            line-height: 1.6;
        }
        /* Excel 预览 */
        #excel-viewer {
            background-color: white;
            padding: 20px;
            max-width: 100%;
            overflow-x: auto;
        }
        #excel-viewer table {
            border-collapse: collapse;
            width: 100%;
            font-size: 12px;
        }
        #excel-viewer th, #excel-viewer td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: left;
        }
        #excel-viewer th {
            background-color: #f8f9fa;
            font-weight: 600;
        }
        /* 图片预览 */
        #image-viewer {
            text-align: center;
            padding: 20px;
        }
        #image-viewer img {
            max-width: 100%;
            max-height: calc(100vh - 100px);
            object-fit: contain;
        }
        /* 加载动画 */
        .loading {
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100%;
            font-size: 16px;
            color: #666;
        }
        .loading::after {
            content: '';
            width: 20px;
            height: 20px;
            border: 2px solid #3498db;
            border-top-color: transparent;
            border-radius: 50%;
            margin-left: 10px;
            animation: spin 1s linear infinite;
        }
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        /* 错误提示 */
        .error {
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            height: 100%;
            color: #e74c3c;
            font-size: 16px;
        }
        .error-icon {
            font-size: 48px;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <div class="preview-header">
        <div class="preview-title">
            {{ $file->original_name }}
        </div>
        <div class="preview-actions">
            <a href="{{ route('file.download', $file) }}" class="btn btn-download" target="_blank">
                下载
            </a>
            @if(\App\Models\WecomConfig::active())
            @php
                $ext = strtolower(pathinfo($file->original_name, PATHINFO_EXTENSION));
                $wtype = in_array($ext, ['jpg','jpeg','png','gif','webp','bmp']) ? 'image' : 'file';
            @endphp
            <button class="btn" style="background-color:#07C160;color:white;border:none;" onclick="showWxSend()">
                发送到企微
            </button>
            @endif
            <button class="btn btn-close" onclick="window.close()">关闭</button>
        </div>
    </div>

    <div class="preview-container">
        <div class="preview-content">
            <div id="loading" class="loading">加载中...</div>
            <div id="error" class="error" style="display: none;">
                <div class="error-icon">⚠️</div>
                <div>文件加载失败</div>
            </div>
            
            <!-- PDF 预览容器 -->
            <div id="pdf-viewer" style="display: none;"></div>
            
            <!-- Word 文档预览容器 -->
            <div id="word-viewer" style="display: none;">
                <div class="word-content" id="word-content"></div>
            </div>
            
            <!-- Excel 预览容器 -->
            <div id="excel-viewer" style="display: none;"></div>
            
            <!-- 图片预览容器 -->
            <div id="image-viewer" style="display: none;"></div>
        </div>
    </div>

    <!-- 引入必要的库 -->
    <script src="{{ asset('js/mammoth.min.js') }}"></script>
    <script src="{{ asset('js/xlsx.full.min.js') }}"></script>
    
    @if(\App\Models\WecomConfig::active())
    <div id="wecomSendForm" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);z-index:9999;justify-content:center;align-items:center;">
        <div style="background:white;border-radius:8px;padding:24px;width:420px;max-height:80vh;overflow-y:auto;box-shadow:0 4px 20px rgba(0,0,0,0.3);">
            <h5 style="margin-top:0;"><i class="fab fa-weixin" style="color:#07C160;"></i> 发送到企业微信</h5>
            <p style="font-size:13px;color:#666;">文件名: <strong>{{ $file->original_name }}</strong></p>
            <form action="{{ route('wecom.file.send') }}" method="POST">
                @csrf
                <input type="hidden" name="file_path" value="{{ Storage::disk('file_repository')->path($file->path) }}">
                <input type="hidden" name="file_name" value="{{ $file->original_name }}">
                <input type="hidden" name="file_type" value="{{ $wtype }}">
                <div style="margin-bottom:12px;">
                    <label style="font-weight:bold;">接收人</label>
                    <div id="previewUserList" style="border:1px solid #ddd;border-radius:4px;padding:8px;max-height:200px;overflow-y:auto;margin-top:4px;">
                        <span style="color:#999;">加载中...</span>
                    </div>
                </div>
                <div style="display:flex;gap:8px;justify-content:flex-end;">
                    <button type="button" onclick="hideWxSend()" style="padding:8px 16px;border:1px solid #ccc;border-radius:4px;background:white;cursor:pointer;">取消</button>
                    <button type="submit" style="padding:8px 16px;background:#07C160;color:white;border:none;border-radius:4px;cursor:pointer;">发送</button>
                </div>
            </form>
        </div>
    </div>
    <script>
        function showWxSend() {
            document.getElementById('wecomSendForm').style.display = 'flex';
            if (!document.getElementById('wecomSendForm').dataset.loaded) {
                fetch('{{ route("wecom.file.bound-users") }}')
                    .then(r => r.json()).then(function(users) {
                        var h = '';
                        if (!users || !users.length) { h = '<span style="color:#999;">暂无已绑定企微的用户</span>'; }
                        else users.forEach(function(u, i) {
                            h += '<label style="display:block;margin:2px 0;font-size:13px;">';
                            h += '<input type="radio" name="wecom_user" value="' + u.wecom_userid + '" ' + (i===0?'checked':'') + '> ';
                            h += u.name + (u.department ? ' (' + u.department.name + ')' : '');
                            h += '</label>';
                        });
                        document.getElementById('previewUserList').innerHTML = h;
                        document.getElementById('wecomSendForm').dataset.loaded = '1';
                    });
            }
        }
        function hideWxSend() { document.getElementById('wecomSendForm').style.display = 'none'; }
    </script>
    @endif

    <script>
        // 文件信息
        const fileId = {{ $file->id }};
        const fileName = '{{ $file->original_name }}';
        const fileExtension = '{{ strtolower(pathinfo($file->original_name, PATHINFO_EXTENSION)) }}';

        // 显示加载状态
        function showLoading() {
            document.getElementById('loading').style.display = 'flex';
            document.getElementById('error').style.display = 'none';
        }

        // 隐藏加载状态
        function hideLoading() {
            document.getElementById('loading').style.display = 'none';
        }

        // 显示错误
        function showError(message) {
            hideLoading();
            document.getElementById('error').style.display = 'flex';
            document.getElementById('error').querySelector('div:last-child').textContent = message || '文件加载失败';
        }

        // 显示预览容器
        function showViewer(viewerId) {
            hideLoading();
            document.getElementById(viewerId).style.display = 'block';
        }

        // 预览 PDF 文件
        function previewPDF(fileUrl) {
            try {
                const viewer = document.getElementById('pdf-viewer');
                viewer.innerHTML = '';
                
                // 创建iframe元素
                const iframe = document.createElement('iframe');
                iframe.src = fileUrl;
                iframe.width = '100%';
                iframe.height = '100%';
                iframe.style.border = 'none';
                iframe.style.display = 'block';
                
                // 设置超时机制
                const timeout = setTimeout(function() {
                    hideLoading();
                    viewer.style.display = 'block';
                }, 3000);
                
                iframe.onload = function() {
                    clearTimeout(timeout);
                    hideLoading();
                    viewer.style.display = 'block';
                };
                
                iframe.onerror = function() {
                    clearTimeout(timeout);
                    console.error('PDF加载失败');
                    showError('PDF 文件预览失败，请尝试下载查看');
                };
                
                viewer.appendChild(iframe);
                
                setTimeout(function() {
                    hideLoading();
                    viewer.style.display = 'block';
                }, 500);
                
            } catch (error) {
                console.error('PDF 预览失败:', error);
                showError('PDF 文件预览失败');
            }
        }

        // 预览 Word 文档
        async function previewWord(fileUrl) {
            try {
                const response = await fetch(fileUrl);
                const arrayBuffer = await response.arrayBuffer();
                
                const result = await mammoth.convertToHtml({ arrayBuffer: arrayBuffer });
                
                document.getElementById('word-content').innerHTML = result.value;
                showViewer('word-viewer');
            } catch (error) {
                console.error('Word 预览失败:', error);
                showError('Word 文档预览失败');
            }
        }

        // 预览 Excel 文件
        async function previewExcel(fileUrl) {
            try {
                const response = await fetch(fileUrl);
                const arrayBuffer = await response.arrayBuffer();
                
                const workbook = XLSX.read(arrayBuffer, { type: 'array' });
                const firstSheetName = workbook.SheetNames[0];
                const worksheet = workbook.Sheets[firstSheetName];
                
                const html = XLSX.utils.sheet_to_html(worksheet, { editable: false });
                
                const viewer = document.getElementById('excel-viewer');
                viewer.innerHTML = html;
                
                showViewer('excel-viewer');
            } catch (error) {
                console.error('Excel 预览失败:', error);
                showError('Excel 文件预览失败');
            }
        }

        // 预览图片
        function previewImage(fileUrl) {
            const viewer = document.getElementById('image-viewer');
            const img = document.createElement('img');
            img.src = fileUrl;
            img.onload = function() {
                showViewer('image-viewer');
            };
            img.onerror = function() {
                showError('图片加载失败');
            };
            viewer.innerHTML = '';
            viewer.appendChild(img);
        }

        // 主函数：根据文件类型选择预览方式
        async function previewFile() {
            const fileUrl = '{{ route('file.preview-content', $file) }}';
            
            switch (fileExtension) {
                case 'pdf':
                    previewPDF(fileUrl);
                    break;
                case 'doc':
                    showError('旧版 .doc 格式不支持在线预览，请下载后使用 Word 查看');
                    break;
                case 'docx':
                    await previewWord(fileUrl);
                    break;
                case 'xls':
                case 'xlsx':
                    await previewExcel(fileUrl);
                    break;
                case 'jpg':
                case 'jpeg':
                case 'png':
                case 'gif':
                case 'bmp':
                case 'svg':
                case 'webp':
                    previewImage(fileUrl);
                    break;
                default:
                    showError('不支持此格式文件预览');
            }
        }

        // 页面加载完成后开始预览
        document.addEventListener('DOMContentLoaded', function() {
            previewFile();
        });
    </script>
</body>
</html>