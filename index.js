const express = require('express');
const xmlbuilder = require('xmlbuilder');
const archiver = require('archiver');
const fs = require('fs-extra');
const path = require('path');
const cron = require('node-cron');
const app = express();
const PORT = 3000;
const TEMP_DIR = path.join(__dirname, 'temp');

app.use(express.json());

function jsonToQtiXml(question) {
  const xmlRoot = xmlbuilder.create('assessmentItem', { version: '1.0', encoding: 'UTF-8' })
    .att('xmlns', 'http://www.imsglobal.org/xsd/imsqti_v2p1')
    .att('xsi:schemaLocation', 'http://www.imsglobal.org/xsd/imsqti_v2p1 http://www.imsglobal.org/xsd/qti/qtiv2p1/imsqti_v2p1.xsd')
    .att('xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance')
    .att('identifier', question.douid || 'default_id')
    .att('title', question.title || 'Default Title')
    .att('adaptive', 'false')
    .att('timeDependent', 'false');

  const itemBody = xmlRoot.ele('itemBody');
  let interactionElement;
  let needsDefaultResponse = true;

  switch (question.type) {
    case 'QBTextMC':
    case 'QBTrueFalse':
      const responseId = `RESPONSE_${question.douid}`;
    
      interactionElement = itemBody.ele('choiceInteraction', {
        responseIdentifier: responseId,
        shuffle: 'true',
        maxChoices: '1'
      });
    
      interactionElement.ele('prompt', {}, question.question);
    
      let correctOptionIndex = null;
      let optionIndex = 1;
    
      for (let i = 1; i <= question.totalOptions; i++) {
        const optionKey = `opt${i}`;
        const optionValue = question[optionKey];
    
        if (optionValue) {
          interactionElement.ele('simpleChoice', { identifier: `OPTION_${optionIndex}` }, optionValue);
    
          if (i.toString() === question.answer) {
            correctOptionIndex = `OPTION_${optionIndex}`;
          }
          optionIndex++;
        }
      }
    
      if (correctOptionIndex) {
        const responseDeclaration = xmlRoot.ele('responseDeclaration', {
          identifier: responseId,
          cardinality: 'single',
          baseType: 'identifier'
        });
    
        const correctResponse = responseDeclaration.ele('correctResponse');
        correctResponse.ele('value', {}, correctOptionIndex);
        needsDefaultResponse = false;
      } else {
        console.warn(`Correct option not found for question ID: ${question.douid}`);
      }
      break;

    case 'QBRecorder':
    case 'QBTakePhoto':
      // interactionElement = itemBody.ele('uploadInteraction', {
      //   responseIdentifier: `RESPONSE_${question.douid}`
      // });

      // interactionElement.ele('prompt', {}, question.question);
      // break;

    case 'QBShortAnswer':
    case 'QBLongQuestion':
      interactionElement = itemBody.ele('extendedTextInteraction', {
        responseIdentifier: `RESPONSE_${question.douid}`,
        expectedLength: "200"
      });

      interactionElement.ele('prompt', {}, question.question);

      const shortAnswerResponseDeclaration = xmlRoot.ele('responseDeclaration', {
        identifier: `RESPONSE_${question.douid}`,
        cardinality: 'single',
        baseType: 'string'
      });

      const shortAnswerCorrectResponse = shortAnswerResponseDeclaration.ele('correctResponse');
      shortAnswerCorrectResponse.ele('value', {}, question.answer.toString());
      needsDefaultResponse = false;
      break;

    case 'QBFillingBlank':
      interactionElement = itemBody.ele('textEntryInteraction', {
        responseIdentifier: `RESPONSE_${question.douid}`,
        expectedLength: "200"
      });
    
      interactionElement.ele('prompt', {}, question.question);
    
      const fillingBlankResponseDeclaration = xmlRoot.ele('responseDeclaration', {
        identifier: `RESPONSE_${question.douid}`,
        cardinality: 'single',
        baseType: 'string'
      });
    
      const fillingBlankCorrectResponse = fillingBlankResponseDeclaration.ele('correctResponse');
    
      let correctAnswer = question.question.match(/\[(.*?)\]/)[1];
      fillingBlankCorrectResponse.ele('value', {}, correctAnswer);
      needsDefaultResponse = false;
      break;

    case 'QBToggleOptions':
      interactionElement = itemBody.ele('hotspotInteraction', {
        responseIdentifier: `RESPONSE_${question.douid}`,
        maxChoices: "1"
      });

      interactionElement.ele('prompt', {}, question.question);
      break;

    case 'QBDragLine':
    case 'DragDropA':
    case 'DragDropC':
      interactionElement = itemBody.ele('associateInteraction', {
        responseIdentifier: `RESPONSE_${question.douid}`,
        shuffle: "true",
        maxAssociations: "3"
      });

      interactionElement.ele('prompt', {}, question.question);
      break;

    default:
      console.warn(`Unknown question type: ${question.type}`);
  }
  
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

  if (needsDefaultResponse) {
    const responseDeclaration = xmlRoot.ele('responseDeclaration', {
      identifier: `RESPONSE_${question.douid}`,
      cardinality: 'single',
      baseType: 'string'
    });

    responseDeclaration.ele('correctResponse').ele('value', {}, 'N/A');
  }

  return xmlRoot.end({ pretty: true });
}

function createManifest(questionFiles) {
  const manifest = xmlbuilder.create('manifest', { version: '1.0', encoding: 'UTF-8' })
    .att('xmlns', 'http://www.imsglobal.org/xsd/imscp_v1p1')
    .att('xmlns:imsmd', 'http://www.imsglobal.org/xsd/imsmd_v1p2')
    .att('xmlns:imsqti', 'http://www.imsglobal.org/xsd/imsqti_v2p1')
    .att('xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance')
    .att('identifier', 'MANIFEST-QTI-1')
    .att('xsi:schemaLocation', 'http://www.imsglobal.org/xsd/imscp_v1p1 imscp_v1p1.xsd http://www.imsglobal.org/xsd/imsmd_v1p2 imsmd_v1p2p2.xsd http://www.imsglobal.org/xsd/imsqti_v2p1 imsqti_v2p1.xsd');

  manifest.ele('organizations');

  const resources = manifest.ele('resources');

  questionFiles.forEach((file, index) => {
    const resource = resources.ele('resource', {
      identifier: `qti_item_${index + 1}`,
      type: 'imsqti_item_xmlv2p1',
      href: file
    });

    const metadata = resource.ele('metadata');
    metadata.ele('schema', 'IMS QTI Item');
    metadata.ele('schemaversion', '2.1');

    const lom = metadata.ele('imsmd:lom');
    const general = lom.ele('imsmd:general');
    general.ele('imsmd:identifier', `qti_v2_item_${index + 1}`);
    const title = general.ele('imsmd:title');
    title.ele('imsmd:langstring', { 'xml:lang': 'en' }, `Metadata Example Item #${index + 1}`);
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
    qtiMetadata.ele('imsqti:feedbackType', 'nonadaptive');
    qtiMetadata.ele('imsqti:solutionAvailable', 'true');
    qtiMetadata.ele('imsqti:toolName', 'XMLSPY');
    qtiMetadata.ele('imsqti:toolVersion', '5.4');
    qtiMetadata.ele('imsqti:toolVendor', 'ALTOVA');

    resource.ele('file', { href: file });
  });

  return manifest.end({ pretty: true });
}

app.post('/convert', async (req, res) => {
  try {
    const questionFiles = [];
    
    req.body.questions.forEach((question, index) => {
      const xmlOutput = jsonToQtiXml(question);
      const fileName = `assessment_item_${index + 1}.xml`;
      fs.writeFileSync(path.join(TEMP_DIR, fileName), xmlOutput);
      questionFiles.push(fileName);
    });

    const manifestOutput = createManifest(questionFiles);
    const manifestFileName = 'imsmanifest.xml';
    fs.writeFileSync(path.join(TEMP_DIR, manifestFileName), manifestOutput);

    const zipFileName = `qti_${Date.now()}.zip`;
    const zipFilePath = path.join(TEMP_DIR, zipFileName);
    const output = fs.createWriteStream(zipFilePath);
    const archive = archiver('zip');

    output.on('close', () => {
      console.log(`ZIP file ${zipFileName} created successfully.`);
      res.download(zipFilePath);
    });

    archive.on('error', err => {
      throw err;
    });

    archive.pipe(output);
    questionFiles.forEach(file => {
      archive.file(path.join(TEMP_DIR, file), { name: file });
    });
    archive.file(path.join(TEMP_DIR, manifestFileName), { name: manifestFileName });
    archive.finalize();
  } catch (error) {
    res.status(500).send('Error converting JSON to XML: ' + error);
  }
});

cron.schedule('0 0 * * *', async () => {
  const files = await fs.readdir(TEMP_DIR);
  const now = Date.now();
  for (const file of files) {
    const filePath = path.join(TEMP_DIR, file);
    const stats = await fs.stat(filePath);
    if ((now - stats.mtimeMs) > 7 * 24 * 60 * 60 * 1000) {
      await fs.unlink(filePath);
      console.log(`Deleted old file: ${file}`);
    }
  }
});

app.get('/', function(req, res){
  res.set('Access-Control-Allow-Origin', '*');
  res.end('QTI converted successfully');
});

app.listen(PORT, () => {
  console.log(`Server is running on port ${PORT}`);
});

app.use(express.static("./public"));

app.use(function(req, res, next) {
  res.status(404).send('Page not found');
});