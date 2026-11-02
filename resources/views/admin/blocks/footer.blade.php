{{-- Thư viện dùng chung của trang quản trị. Trang nào cần thêm (pdfmake, Chart...) thì @push('scripts'). --}}
<script src="{{ asset('admin/vendors/jquery/dist/jquery.min.js') }}"></script>
<script src="{{ asset('admin/vendors/bootstrap/dist/js/bootstrap.bundle.min.js') }}"></script>
<script src="{{ asset('admin/vendors/nprogress/nprogress.js') }}"></script>
<script src="{{ asset('admin/vendors/moment/min/moment.min.js') }}"></script>
<script src="{{ asset('admin/vendors/dropzone/dist/min/dropzone.min.js') }}"></script>
<script src="{{ asset('admin/vendors/jQuery-Smart-Wizard/js/jquery.smartWizard.js') }}"></script>
{{-- custom.js của Gentelella chứa logic wizard Thêm tour (init_SmartWizard) nên vẫn giữ --}}
<script src="{{ asset('admin/build/js/custom.js') }}"></script>
<script>
    // Bảng danh sách đã dùng admin-paginate.js (tìm + phân trang). Tắt DataTables cũ để không hiện hai bộ điều khiển.
    window.init_DataTables = function () {};
</script>
<script src="{{ asset('vendor/flasher/toastr.min.js') }}"></script>
<script src="{{ asset('admin/vendors/sweetalert2/sweetalert2.all.min.js') }}"></script>
<script src="{{ asset('admin/assets/js/ckeditor/ckeditor.js') }}"></script>
<script src="{{ asset('admin/assets/js/jquery.datetimepicker.full.min.js') }}"></script>
<script src="{{ asset('admin/assets/js/custom-js.js') }}"></script>
<script src="{{ asset('admin/assets/js/admin-paginate.js') }}?v=3"></script>
<script src="{{ asset('admin/assets/js/admin-ui.js') }}?v={{ @filemtime(public_path('admin/assets/js/admin-ui.js')) }}"></script>
@stack('scripts')

</body>

</html>
