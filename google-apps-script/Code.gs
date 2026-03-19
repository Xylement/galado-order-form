// GALADO Order Form — Google Apps Script
// Paste this into Google Apps Script (script.google.com)
// Then deploy as Web App (Execute as: Me, Access: Anyone)

function doPost(e) {
  try {
    var sheet = SpreadsheetApp.getActiveSpreadsheet().getActiveSheet();
    var data = JSON.parse(e.postData.contents);

    // Add headers if sheet is empty
    if (sheet.getLastRow() === 0) {
      sheet.appendRow([
        "Submitted At",
        "Date",
        "Order ID",
        "Handled By",
        "Phone Model",
        "Case Type",
        "Design",
        "Case Color",
        "Font Style",
        "Text to Insert",
        "Text Position",
        "Preview Required",
        "Special Requests / Remarks",
        "Customer Name",
        "Phone Number",
        "Collection Method",
        "Delivery Address",
        "Email Address"
      ]);

      // Bold the header row
      sheet.getRange(1, 1, 1, 18).setFontWeight("bold");
    }

    // Append the order data
    sheet.appendRow([
      data.submittedAt || new Date().toISOString(),
      data.date,
      data.orderId,
      data.handledBy,
      data.phoneModel,
      data.caseType,
      data.design,
      data.caseColor,
      data.fontStyle,
      data.textInsert,
      data.textPosition,
      data.previewRequired,
      data.remarks,
      data.customerName,
      data.phoneNumber,
      data.collection,
      data.address,
      data.email
    ]);

    return ContentService
      .createTextOutput(JSON.stringify({ status: "success", message: "Order saved" }))
      .setMimeType(ContentService.MimeType.JSON);

  } catch (error) {
    return ContentService
      .createTextOutput(JSON.stringify({ status: "error", message: error.toString() }))
      .setMimeType(ContentService.MimeType.JSON);
  }
}

// Optional: test GET to verify deployment works
function doGet(e) {
  return ContentService
    .createTextOutput(JSON.stringify({ status: "ok", message: "GALADO Order Form API is running" }))
    .setMimeType(ContentService.MimeType.JSON);
}
