const express = require('express');
const xmlbuilder = require('xmlbuilder');
const archiver = require('archiver');
const fs = require('fs-extra');
const path = require('path');
const cron = require('node-cron');
const cors = require('cors');
const app = express();
const PORT = 3000;
const TEMP_DIR = path.join(__dirname, 'temp');

app.use(express.json());

function jsonToQtiXml(data) {
  console.log(data);

  const assessmentItem = xmlbuilder.create('assessmentItem')
    .att('identifier', 'default_id')
    .att('title', 'Default Title')
    .att('adaptive', 'false')
    .att('timeDependent', 'false');

  const itemBody = assessmentItem.ele('itemBody');

  data.questions.forEach(question => {
    let interactionElement;
  
    switch (question.type) {
      case 'QBTextMC':
      case 'QBTrueFalse':
        interactionElement = itemBody.ele('choiceInteraction', {
          responseIdentifier: `RESPONSE_${question.douid}`,
          shuffle: 'true',
          maxChoices: '1'
        });
  
        interactionElement.ele('prompt', {}, question.question);
        
        let optionIndex = 1; // Start from 1
        Object.entries(question).forEach(([key, value]) => {
          if (key.startsWith('opt') && value) {
            interactionElement.ele('simpleChoice', { identifier: `OPTION_${optionIndex}` }, value);
  
            // Assuming question.correctOption holds the index of the correct option
            if (key === `opt${question.correctOption}`) {
              itemBody.ele('responseDeclaration', {
                identifier: `RESPONSE_${question.douid}`,
                cardinality: 'single',
                baseType: 'identifier'
              }).ele('correctResponse').ele('value', {}, `OPTION_${optionIndex}`);
            }
  
            optionIndex++; // Increment the counter for each option
          }
        });
  
        break;
  
      case 'QBRecorder':
      case 'QBTakePhoto':
        interactionElement = itemBody.ele('uploadInteraction', {
          responseIdentifier: `RESPONSE_${question.douid}`
        });
  
        interactionElement.ele('prompt', {}, question.question);
  
        break;
  
      case 'QBShortAnswer':
      case 'QBLongQuestion':
        interactionElement = itemBody.ele('extendedTextInteraction', {
          responseIdentifier: `RESPONSE_${question.douid}`
        });
  
        interactionElement.ele('prompt', {}, question.question);
  
        break;
  
      case 'QBFillingBlank':
        interactionElement = itemBody.ele('textEntryInteraction', {
          responseIdentifier: `RESPONSE_${question.douid}`
        });
  
        interactionElement.ele('prompt', {}, question.question);
  
        break;
  
      case 'QBToggleOptions':
        interactionElement = itemBody.ele('hotspotInteraction', {
          responseIdentifier: `RESPONSE_${question.douid}`
        });
  
        interactionElement.ele('prompt', {}, question.question);
  
        break;
  
      case 'QBDragLine':
      case 'DragDropA':
      case 'DragDropC':
        interactionElement = itemBody.ele('matchInteraction', {
          responseIdentifier: `RESPONSE_${question.douid}`
        });
  
        interactionElement.ele('prompt', {}, question.question);
  
        break;
  
      default:
        console.warn(`Unknown question type: ${question.type}`);
        return;
    }
  
    // 添加媒體文件
    if (question.asset && question.asset.files) {
      question.asset.files.forEach(file => {
        if (file.url) {
          const url = `https://oka.blob.core.windows.net/media/${file.url}`;
          const fileName = file.name.toLowerCase();
  
          if (fileName.endsWith('.jpg') || fileName.endsWith('.jpeg') || fileName.endsWith('.gif') || fileName.endsWith('.png')) {
            interactionElement.ele('img', { src: url });
          } else if (fileName.endsWith('.mp3')) {
            interactionElement.ele('audio', { controls: '' }).ele('source', { src: url, type: 'audio/mpeg' });
          }
        }
      });
    }
  });


  data.questions.forEach(question => {
    const responseDeclaration = assessmentItem.ele('responseDeclaration', {
      identifier: `RESPONSE_${question.douid}`,
      cardinality: 'single',
      baseType: 'identifier',
    });
  
    const correctResponse = responseDeclaration.ele('correctResponse');
  
    if (question.type === 'QBTextMC') {
      // Assuming question.answer contains the correct option index
      correctResponse.ele('value', {}, `OPTION_${question.answer}`);
    } else if (question.type === 'QBShortAnswer' || question.type === 'QBLongQuestion') {
      // For text-based answers
      correctResponse.ele('value', {}, question.answer.toString());
    } else {
      // Use 'N/A' for types that don't have a straightforward correct answer
      correctResponse.ele('value', {}, 'N/A');
    }
  });

  return assessmentItem.end({ pretty: true });
}

//create manifest file
function createManifest() {
  const manifest = xmlbuilder.create('manifest', { version: '1.0', encoding: 'UTF-8' })
    .att('xmlns', 'http://www.imsglobal.org/xsd/imscp_v1p1')
    .att('xmlns:imsmd', 'http://www.imsglobal.org/xsd/imsmd_v1p2')
    .att('xmlns:imsqti', 'http://www.imsglobal.org/xsd/imsqti_v2p0')
    .att('xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance')
    .att('identifier', 'MANIFEST-QTI-1')
    .att('xsi:schemaLocation', 'http://www.imsglobal.org/xsd/imscp_v1p1 imscp_v1p1.xsd http://www.imsglobal.org/xsd/imsmd_v1p2 imsmd_v1p2p2.xsd http://www.imsglobal.org/xsd/imsqti_v2p0 imsqti_v2p0.xsd');

  manifest.ele('organizations');

  const resources = manifest.ele('resources');
  const resource = resources.ele('resource', {
    identifier: 'choice',
    type: 'imsqti_item_xmlv2p0',
    href: 'assessment.xml'
  });

  const metadata = resource.ele('metadata');
  metadata.ele('schema', 'IMS QTI Item');
  metadata.ele('schemaversion', '2.0');

  const lom = metadata.ele('imsmd:lom');
  const general = lom.ele('imsmd:general');
  general.ele('imsmd:identifier', 'qti_v2_item_01');
  const title = general.ele('imsmd:title');
  title.ele('imsmd:langstring', { 'xml:lang': 'en' }, 'Metadata Example Item #1');
  const description = general.ele('imsmd:description');
  description.ele('imsmd:langstring', { 'xml:lang': 'en' }, 'This is a dummy item');

  const lifecycle = lom.ele('imsmd:lifecycle');
  const version = lifecycle.ele('imsmd:version');
  version.ele('imsmd:langstring', { 'xml:lang': 'en' }, '1.0');
  const status = lifecycle.ele('imsmd:status');
  const source = status.ele('imsmd:source');
  source.ele('imsmd:langstring', { 'xml:lang': 'x-none' }, 'LOMv1.0');
  const value = status.ele('imsmd:value');
  value.ele('imsmd:langstring', { 'xml:lang': 'x-none' }, 'Draft');

  const qtiMetadata = metadata.ele('imsqti:qtiMetadata');
  qtiMetadata.ele('imsqti:timeDependent', 'false');
  qtiMetadata.ele('imsqti:interactionType', 'choiceInteraction');
  qtiMetadata.ele('imsqti:feedbackType', 'nonadaptive');
  qtiMetadata.ele('imsqti:solutionAvailable', 'true');
  qtiMetadata.ele('imsqti:toolName', 'XMLSPY');
  qtiMetadata.ele('imsqti:toolVersion', '5.4');
  qtiMetadata.ele('imsqti:toolVendor', 'ALTOVA');

  resource.ele('file', { href: 'assessment.xml' });

  return manifest.end({ pretty: true });
}
//create canvas file
//   function createCanvas() {
//     const canvas = xmlbuilder.create('assessment.meta')
//     .att('identifier','canvas_id')
//     const quizs = canvas.ele('quizs');
//     const quiz = quizs.ele('quiz', {
//       identifier: 'g18f2079963accb42260977ee5f883a63',
//       xmlns: 'http://canvas.instructure.com/xsd/cccv1p0',
//       'xmlns:xsi': 'http://www.w3.org/2001/XMLSchema-instance',
//       'xsi:schemaLocation':'http://canvas.instructure.com/xsd/cccv1p0 https://canvas.instructure.com/xsd/cccv1p0.xsd'
//     });
//     quiz.ele('file', { href: 'assessment_meta.xml' });

//     return canvas.end({ pretty: true });

// }
//===========================================================

app.post('/convert', async (req, res) => {
  try {
    const xmlOutput = jsonToQtiXml(req.body);
    // const xmlCanvas = createCanvas();
    const manifestOutput = createManifest();
    const fileName = `qti_${Date.now()}.zip`;
    const filePath = path.join(TEMP_DIR, fileName);
    const output = fs.createWriteStream(filePath);
    const archive = archiver('zip');

    output.on('close', () => {
      console.log(`ZIP file ${fileName} created successfully.`);
      res.download(filePath);
    });

    archive.on('error', err => {
      throw err;
    });

    archive.pipe(output);
    archive.append(xmlOutput, { name: 'assessment.xml' });
    archive.append(manifestOutput, { name: 'imsmanifest.xml' });
    // archive.append(xmlCanvas, { name: 'assessment_meta.xml' });
    archive.finalize();
  } catch (error) {
    res.status(500).send('Error converting JSON to XML: ' + error);
  }
});

// Schedule a task to delete files older than 7 days
cron.schedule('0 0 * * *', async () => {
  const files = await fs.readdir(TEMP_DIR);
  const now = Date.now();
//files.forEach(async (file =>{
  for (const file of files) {
    const filePath = path.join(TEMP_DIR, file);
    const stats = await fs.stat(filePath);
    // will remove files if over 7 days
    if ((now - stats.mtimeMs) > 7 * 24 * 60 * 60 * 1000) {
      await fs.unlink(filePath);
      console.log(`Deleted old file: ${file}`);
    }
  }
});

app.get('/', function(req, res){
  res.set('Access-Control-Allow-origin', '*');
  res.end('QTI converted successfully');
})

app.listen(PORT, () => {
  console.log(`Server is running on port ${PORT}`);
});

app.use(express.static("./public"));

app.use(function(req, res, next) {
  res.status(404).send('Page not found');
});

//剩餘步驟：
//1.在itemBody加上url -->done
// QBRecorder
// QBTakePhoto
// QBShortAnswer
// QBLongQuestion
// QBFillingBlank
// QBTrueFalse
// QBToggleOptions
//2.在zip裏面增加一個'manifest'的文件 ->done
//3.manifest，xml diff