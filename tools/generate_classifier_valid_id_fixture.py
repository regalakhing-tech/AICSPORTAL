"""Generate a visibly non-credential synthetic PDF for Phase 1 testing."""
from pathlib import Path

from reportlab.lib.colors import HexColor
from reportlab.lib.pagesizes import letter
from reportlab.pdfgen import canvas


OUTPUT = Path(__file__).resolve().parents[1] / 'output' / 'pdf' / 'sample-classifier-valid-id.pdf'


def build():
    OUTPUT.parent.mkdir(parents=True, exist_ok=True)
    pdf = canvas.Canvas(str(OUTPUT), pagesize=letter)
    width, height = letter
    pdf.setTitle('Sample Classifier Valid ID - Not a Real Government ID')
    pdf.setFillColor(HexColor('#B91C1C'))
    pdf.rect(0, height - 76, width, 76, fill=1, stroke=0)
    pdf.setFillColorRGB(1, 1, 1)
    pdf.setFont('Helvetica-Bold', 24)
    pdf.drawCentredString(width / 2, height - 34, 'SAMPLE - FOR SYSTEM TESTING ONLY')
    pdf.setFont('Helvetica-Bold', 14)
    pdf.drawCentredString(width / 2, height - 58, 'NOT A REAL GOVERNMENT ID')

    pdf.setFillColor(HexColor('#111827'))
    pdf.setFont('Helvetica-Bold', 19)
    pdf.drawString(54, height - 118, 'SAMPLE NATIONAL ID TRAINING FORMAT')
    pdf.setFont('Helvetica-Bold', 15)
    pdf.drawString(54, height - 145, 'IDENTIFICATION CARD')
    pdf.setStrokeColor(HexColor('#9CA3AF'))
    pdf.line(54, height - 156, width - 54, height - 156)

    rows = [
        ('Issuing Organization', 'Sample Government Testing Office'),
        ('Full Name', 'JUAN DELA CRUZ'),
        ('Date of Birth', 'January 2, 1950'),
        ('Sex', 'Male'),
        ('Address', 'San Jose de Buenavista, Antique'),
        ('ID Number', 'TEST-000001'),
        ('Valid Until', 'December 31, 2030'),
    ]
    y = height - 192
    for label, value in rows:
        pdf.setFillColor(HexColor('#4B5563'))
        pdf.setFont('Helvetica-Bold', 10)
        pdf.drawString(64, y, label.upper())
        pdf.setFillColor(HexColor('#111827'))
        pdf.setFont('Helvetica', 14)
        pdf.drawString(230, y - 1, value)
        pdf.setStrokeColor(HexColor('#E5E7EB'))
        pdf.line(64, y - 12, width - 64, y - 12)
        y -= 54

    pdf.setFillColor(HexColor('#B91C1C'))
    pdf.setFont('Helvetica-Bold', 13)
    pdf.drawCentredString(width / 2, 54, 'SAMPLE ONLY - NOT VALID FOR IDENTIFICATION')
    pdf.setFillColor(HexColor('#6B7280'))
    pdf.setFont('Helvetica', 8)
    pdf.drawCentredString(width / 2, 37, 'Contains no real seal, logo, credential number format, or issuing authority.')
    pdf.showPage()
    pdf.save()
    return OUTPUT


if __name__ == '__main__':
    print(build())
