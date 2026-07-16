' ============================================================
'   نظام المتوكل لإدارة النقل والتخليص الجمركي
'   وحدة الماكرو  —  Al-Motawakel Macros Module
'   انسخ كل محتوى هذا الملف والصقه في وحدة (Module) جديدة
'   داخل محرر أكواد إكسيل (Alt + F11).
' ============================================================
Option Explicit

Private Const DASH As String = "لوحة التحكم"
Private Const REPORTS As String = "التقارير"

' -------- يعمل تلقائياً عند فتح الملف --------
Sub Auto_Open()
    Application.Calculation = xlCalculationAutomatic
    On Error Resume Next
    ThisWorkbook.Worksheets(DASH).Activate
End Sub

' -------- القفز لأول صف فارغ في صفحة البيانات الحالية --------
Sub GoToNewRow()
    Dim ws As Worksheet: Set ws = ActiveSheet
    Dim r As Long: r = 4
    Do While Trim(CStr(ws.Cells(r, 2).Value)) <> "" And r < 4000
        r = r + 1
    Loop
    ws.Cells(r, 2).Select
    MsgBox "الصف الجديد جاهز — اكتب التاريخ في الخلية المحددة، وباقي الحسابات تتم تلقائياً.", _
           vbInformation, "المتوكل"
End Sub

' -------- بحث سريع في كل صفحات البيانات --------
Sub QuickSearch()
    Dim term As String
    term = InputBox("اكتب ما تريد البحث عنه (رقم بوليصة / اسم / رقم حاوية / رقم فاتورة ...):", _
                    "بحث سريع — المتوكل")
    If Trim(term) = "" Then Exit Sub

    Dim names As Variant, s As Variant, ws As Worksheet, f As Range
    names = Array("البوالص", "التخليص", "مولدات ايجي كول", "مولدات الشجيع", "شامل")
    For Each s In names
        Set ws = Nothing
        On Error Resume Next
        Set ws = ThisWorkbook.Worksheets(CStr(s))
        On Error GoTo 0
        If Not ws Is Nothing Then
            Set f = ws.Cells.Find(What:=term, LookIn:=xlValues, LookAt:=xlPart)
            If Not f Is Nothing Then
                ws.Activate
                f.Select
                MsgBox "تم العثور على النتيجة في صفحة: " & ws.Name, vbInformation, "بحث"
                Exit Sub
            End If
        End If
    Next s
    MsgBox "لا توجد نتائج مطابقة لـ: " & term, vbExclamation, "بحث"
End Sub

' -------- تصدير الصفحة الحالية إلى PDF بجوار الملف --------
Sub ExportActiveToPDF()
    Dim p As String
    p = ThisWorkbook.Path & Application.PathSeparator & ActiveSheet.Name & ".pdf"
    On Error GoTo err
    ActiveSheet.ExportAsFixedFormat Type:=xlTypePDF, Filename:=p, Quality:=xlQualityStandard
    MsgBox "تم تصدير الصفحة إلى ملف PDF:" & vbCrLf & p, vbInformation, "تصدير PDF"
    Exit Sub
err:
    MsgBox "تعذّر التصدير. تأكد من أن الملف محفوظ على القرص أولاً.", vbExclamation, "تصدير PDF"
End Sub

' -------- تصدير كل التقارير واللوحة إلى PDF واحد --------
Sub ExportReportsToPDF()
    Dim p As String
    p = ThisWorkbook.Path & Application.PathSeparator & "تقارير المتوكل.pdf"
    On Error GoTo err
    ThisWorkbook.Worksheets(Array(DASH, REPORTS)).Select
    ActiveSheet.ExportAsFixedFormat Type:=xlTypePDF, Filename:=p, Quality:=xlQualityStandard
    ThisWorkbook.Worksheets(DASH).Select
    MsgBox "تم تصدير اللوحة والتقارير إلى:" & vbCrLf & p, vbInformation, "تصدير PDF"
    Exit Sub
err:
    MsgBox "تعذّر التصدير. تأكد من حفظ الملف على القرص أولاً.", vbExclamation, "تصدير PDF"
End Sub

' -------- نسخة احتياطية بختم التاريخ والوقت --------
Sub BackupWorkbook()
    Dim stamp As String, p As String
    stamp = Format(Now, "yyyy-mm-dd_hh-nn")
    p = ThisWorkbook.Path & Application.PathSeparator & "نسخة احتياطية - " & stamp & ".xlsx"
    On Error GoTo err
    Application.DisplayAlerts = False
    ThisWorkbook.SaveCopyAs p
    Application.DisplayAlerts = True
    MsgBox "تم حفظ نسخة احتياطية:" & vbCrLf & p, vbInformation, "نسخة احتياطية"
    Exit Sub
err:
    Application.DisplayAlerts = True
    MsgBox "تعذّر حفظ النسخة الاحتياطية.", vbExclamation, "نسخة احتياطية"
End Sub

' -------- تحديث كل الحسابات والتقارير --------
Sub RefreshAll()
    Application.CalculateFull
    MsgBox "تم تحديث كل الحسابات والتقارير واللوحة.", vbInformation, "تحديث"
End Sub

' -------- الانتقال للوحة التحكم --------
Sub GoDashboard()
    ThisWorkbook.Worksheets(DASH).Activate
    ThisWorkbook.Worksheets(DASH).Range("A1").Select
End Sub

' ============================================================
'   إنشاء شريط أزرار احترافي في لوحة التحكم (شغّله مرة واحدة)
' ============================================================
Sub AddButtonBar()
    Dim ws As Worksheet: Set ws = ThisWorkbook.Worksheets(DASH)
    Dim shp As Shape

    ' حذف أي أزرار قديمة
    Dim k As Long
    For k = ws.Shapes.Count To 1 Step -1
        If Left(ws.Shapes(k).Name, 4) = "btn_" Then ws.Shapes(k).Delete
    Next k

    Dim btns As Variant
    btns = Array( _
        Array("btn_new", "صف جديد", "GoToNewRow", 21, 53, 91), _
        Array("btn_find", "بحث سريع", "QuickSearch", 20, 116, 111), _
        Array("btn_pdf", "طباعة PDF", "ExportReportsToPDF", 201, 162, 39), _
        Array("btn_backup", "نسخة احتياطية", "BackupWorkbook", 91, 62, 122), _
        Array("btn_refresh", "تحديث", "RefreshAll", 155, 44, 44))

    Dim i As Long, c As Long, rng As Range
    For i = 0 To UBound(btns)
        c = 1 + i * 2
        Set rng = ws.Range(ws.Cells(3, c), ws.Cells(3, c + 1))
        Set shp = ws.Shapes.AddShape(msoShapeRoundedRectangle, _
                    rng.Left + 1, rng.Top + 1, rng.Width - 2, rng.Height - 2)
        shp.Name = btns(i)(0)
        shp.OnAction = btns(i)(2)
        shp.Line.Visible = msoFalse
        shp.Fill.ForeColor.RGB = RGB(btns(i)(3), btns(i)(4), btns(i)(5))
        With shp.TextFrame2.TextRange
            .Text = btns(i)(1)
            .Font.Size = 10
            .Font.Bold = msoTrue
            .Font.Fill.ForeColor.RGB = RGB(255, 255, 255)
        End With
        shp.TextFrame2.VerticalAnchor = msoAnchorMiddle
        shp.TextFrame2.HorizontalAnchor = msoAnchorCenter
    Next i

    MsgBox "تم إنشاء شريط الأزرار أعلى لوحة التحكم.", vbInformation, "المتوكل"
End Sub
