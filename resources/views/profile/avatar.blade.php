@extends('layouts.app')

@section('content')
<div class="container mt-5">
    <div class="row justify-content-center">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h3 class="card-title mb-0">上传头像</h3>
                    <a href="{{ route('profile.edit') }}" class="btn btn-sm btn-outline-secondary">
                        <i class="fa fa-arrow-left"></i> 返回基本资料
                    </a>
                </div>
                <div class="card-body">
                    <!-- 当前头像预览 -->
                    <div class="text-center mb-4">
                        <div class="avatar-preview mx-auto">
                            @php
                                $currentUser = auth()->user();
                            @endphp
                            @if($currentUser && $currentUser->avatar)
                                @php
                                    $avatarPath = str_starts_with($currentUser->avatar, 'defaults/') 
                                        ? 'avatars/' . $currentUser->avatar 
                                        : 'storage/avatars/' . $currentUser->avatar;
                                @endphp
                                <img src="{{ asset($avatarPath) }}" 
                                     alt="当前头像" 
                                     class="img-thumbnail rounded-circle"
                                     style="width: 150px; height: 150px; object-fit: cover;">
                            @else
                                <div class="d-flex align-items-center justify-content-center rounded-circle bg-secondary text-white"
                                     style="width: 150px; height: 150px; font-size: 60px;">
                                    <span>{{ mb_substr($currentUser ? $currentUser->name : 'U', 0, 1) }}</span>
                                </div>
                            @endif
                        </div>
                        <p class="mt-2 text-muted">当前头像</p>
                    </div>

                    <!-- 上传表单 -->
                    <form action="{{ route('profile.avatar.upload') }}" method="POST" enctype="multipart/form-data">
                        @csrf
                        <div class="mb-3">
                            <label for="avatar" class="form-label">选择新头像</label>
                            <input type="file" name="avatar" id="avatar" class="form-control" onchange="previewImage(event)">
                            <small class="text-muted">支持 JPG、PNG、GIF 格式，最大 2MB</small>
                        </div>

                        <!-- 图片预览 -->
                        <div id="previewContainer" class="text-center mt-4" style="display: none;">
                            <img id="imagePreview" src="#" alt="预览" class="img-thumbnail rounded-circle" style="width: 150px; height: 150px; object-fit: cover;">
                            <p class="mt-2 text-muted">新头像预览</p>
                        </div>

                        <button type="submit" class="btn btn-primary mt-4">上传头像</button>

                        @if($errors->any())
                            <div class="alert alert-danger mt-4">
                                @foreach($errors->all() as $error)
                                    {{ $error }}<br>
                                @endforeach
                            </div>
                        @endif
                    </form>
                </div>
            </div>
        </div>
    </div>
    
    <!-- 默认头像仓库 -->
    <div class="row justify-content-center mt-4">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">默认头像仓库</h3>
                </div>
                <div class="card-body">
                    <p class="text-muted mb-3">选择一个默认头像作为您的新头像：</p>
                    <div class="row">
                        @php
                            $defaultAvatars = ['avatar1.svg', 'avatar2.svg', 'avatar3.svg', 'avatar4.svg', 'avatar5.svg'];
                        @endphp
                        @foreach($defaultAvatars as $avatar)
                            <div class="col-md-4 mb-3 text-center">
                                <form action="{{ route('profile.avatar.upload') }}" method="POST">
                                    @csrf
                                    <input type="hidden" name="default_avatar" value="defaults/{{ $avatar }}">
                                    <img src="{{ asset('avatars/defaults/' . $avatar) }}" 
                                         alt="默认头像{{ $loop->iteration }}"
                                         class="img-thumbnail rounded-circle mb-2 default-avatar"
                                         style="width: 80px; height: 80px; object-fit: cover; cursor: pointer;"
                                         onclick="this.parentElement.submit()">
                                    <br>
                                    <small class="text-muted">头像{{ $loop->iteration }}</small>
                                </form>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function previewImage(event) {
    const input = event.target;
    const previewContainer = document.getElementById('previewContainer');
    const imagePreview = document.getElementById('imagePreview');
    
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        
        reader.onload = function(e) {
            imagePreview.src = e.target.result;
            previewContainer.style.display = 'block';
        }
        
        reader.readAsDataURL(input.files[0]);
    } else {
        previewContainer.style.display = 'none';
    }
}
</script>
@endsection
