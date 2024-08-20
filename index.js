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
    switch (question.type) {
      case 'QBTextMC':
        const choiceInteraction = itemBody.ele('choiceInteraction', {
          responseIdentifier: `RESPONSE_${question.douid}`,
          shuffle: 'true',
          maxChoices: '1'
        });

        choiceInteraction.ele('prompt', {}, question.question);

        Object.entries(question).forEach(([key, value], index) => {
          if (key.startsWith('opt') && value) {
            choiceInteraction.ele('simpleChoice', { identifier: `OPTION_${index + 1}` }, value);
          }
        });

        break;
        case 'QBRecorder':
        case 'QBTakePhoto':
        case 'QBShortAnswer':
        case 'QBLongQuestion':
        case 'QBFillingBlank':
        case 'QBToggleOptions':
        case 'QBDragLine':
        case 'DragDropA':
        case 'DragDropC':
        const genericInteraction = itemBody.ele('textEntryInteraction', {
          responseIdentifier: `RESPONSE_${question.douid}`,
          shuffle: 'false',
          expectedLength: '',
        });

        genericInteraction.ele('prompt', {}, question.question);

        break;
      default:
        console.warn(`Unknown question type: ${question.type}`);
    }
  });

  data.questions.forEach(question => {
    //start
    if (question && question.asset && question.asset.name) {
      const fileName = question.asset.files;
      Object.entries(fileName.name).forEach(([key, value], index) => {
        // let key = question.asset.name;
          if (key.endsWith('.jpg') || key.endsWith('.jpeg') || key.endsWith('.gif') || key.endsWith('.png')|| key.endsWith('.mp3')){
          let url = fileName.url;
          let updatedData = "https://oka.blob.core.windows.net/media/ "+ url;
          const p = itemBody.ele('p', {
            url: `${updatedData} + ${key}`,
          });
          p.ele('p', {}, fileName.url);
        }
      })
    }

    const responseDeclaration = assessmentItem.ele('responseDeclaration', {
      identifier: `RESPONSE_${question.douid}`,
      cardinality: 'single',
      baseType: 'identifier',
    });

    const correctResponse = responseDeclaration.ele('correctResponse');
    if (question.type === 'QBTextMC') {
      correctResponse.ele('value', {}, question.answer);
    } else if (question.type === 'QBShortAnswer') {
      correctResponse.ele('value', {}, question.answer.toString());
    } else {
      correctResponse.ele('value', {}, 'N/A');
    }
  });

  return assessmentItem.end({ pretty: true });
}

//create manifest file
function createManifest() {
  const manifest = xmlbuilder.create('manifest')
    .att('identifier', 'manifest_id')
    .att('version', '1.0');

  const resources = manifest.ele('resources');
  const resource = resources.ele('resource', {
    identifier: 'resource_1',
    type: 'imsqti_item_xmlv2p1',
    href: 'assessment.xml'
  });

  resource.ele('file', { href: 'assessment.xml' });

  return manifest.end({ pretty: true });
}
//===========================================================

app.post('/convert', async (req, res) => {
  try {
    const xmlOutput = jsonToQtiXml(req.body);
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

// app.get('/test', (req, res) => {
//   res.send('Server is working!');
// });

app.use(express.static("./public"));

app.use(function(req, res, next) {
  res.status(404).send('Page not found');
});

//剩餘步驟：
//1.在itemBody加上url 
//2.在zip裏面增加一個'manifest'的文件 ->done
//3.manifest，xml diff