const express = require('express');
const xmlbuilder = require('xmlbuilder');
const archiver = require('archiver');
const fs = require('fs-extra');
const axios = require('axios');
const cors = require('cors');
const path = require('path');
const cron = require('node-cron');
const app = express();
const { v4: uuidv4 } = require('uuid');
const PORT = 3000;
const TEMP_DIR = path.join(__dirname, 'temp');

app.use(cors());
app.use(express.json());

function jsonToQtiXml(question) {
  const xmlRoot = xmlbuilder.create('assessmentItem', { version: '1.0', encoding: 'UTF-8' })
    .att('xmlns', 'http://www.imsglobal.org/xsd/imsqti_v2p1')
    .att('xsi:schemaLocation', 'http://www.imsglobal.org/xsd/imsqti_v2p1 http://www.imsglobal.org/xsd/qti/qtiv2p1.xsd')
    .att('xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance')
    .att('identifier', question.douid || 'default_id')
    .att('title', question.title || 'Default Title')
    .att('adaptive', 'false')
    .att('timeDependent', 'false')
    .att('xml:lang', 'en');

      // 添加responseDeclaration 和 outcomeDeclaration
    xmlRoot.ele('responseDeclaration', {
      identifier: 'RESPONSE',
      cardinality: 'single',
      baseType: 'identifier'
    }).ele('correctResponse').ele('value', {}, '');

    xmlRoot.ele('outcomeDeclaration', {
      identifier: 'SCORE',
      cardinality: 'single',
      baseType: 'float'
    }).ele('defaultValue').ele('value', {}, '');

  // 創建 itemBody 
    const itemBody = xmlRoot.ele('itemBody');
    // const mediaElement = itemBody.ele('div');
     const pElement = itemBody.ele('div');
     const innerElement = pElement.ele('qh5:figure')

    // 將媒體文件放在itembody裏面但在題型之前
    if (question.asset && question.asset.files) {
      for (const file of question.asset.files) {
        if (file.url) {
          const fileName = file.name.toLowerCase();
          const fileSavePath = path.posix.join(fileName);
          console.log('File save path:', fileSavePath);

          if (fileName.endsWith('.jpg') || fileName.endsWith('.jpeg') || fileName.endsWith('.gif') || fileName.endsWith('.png')) {
            innerElement.ele('img', { src: 'images'+'/'+ fileSavePath, alt: `${file.name}`,width:`${file.width}`,height:`${file.height}`});
          } else if (fileName.endsWith('.mp3')) {
            const audioElement = pElement.ele('audio', { controls: 'controls' });
            audioElement.ele('source', { uri: fileSavePath+'/', type: 'audio/mpeg' });
            // Optionally add fallback text for browsers that do not support the audio element
            audioElement.txt('Your browser does not support the audio element.');
          }

          // if (fileName.endsWith('.jpg') || fileName.endsWith('.jpeg')) {
          //   mediaElement = itemBody.ele('object', { type: 'image/jpeg', data: fileSavePath });
          // } else if (fileName.endsWith('.png')) {
          //   mediaElement = itemBody.ele('object', { type: 'image/png', data: fileSavePath });
          // } else if (fileName.endsWith('.gif')) {
          //   mediaElement = itemBody.ele('object', { type: 'image/gif', data: fileSavePath });
          // } else if (fileName.endsWith('.mp3')) {
          //   mediaElement = itemBody.ele('object', { type: 'audio/mpeg', data: fileSavePath });
          //   console.log('Audio object created:', mediaElement.name);
          //   mediaElement.ele('param', { name: 'autoplay', value: 'false' });
          //   mediaElement.ele('param', { name: 'controls', value: 'true' });
          // }
        }
      }
    }
    

  // 問題類型
  switch (question.type) {
    case 'QBTextMC':
      const responseId = `RESPONSE_${question.douid}`;
      const interactionElement = itemBody.ele('choiceInteraction', {
        responseIdentifier: responseId,
        shuffle: 'false',
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
      } else {
        console.warn(`Correct option not found for question ID: ${question.douid}`);
      }
      break;

    case 'QBTrueFalse':
      const trueFalseInteraction = itemBody.ele('choiceInteraction', {
        responseIdentifier: `RESPONSE_${question.douid}`,
        shuffle: 'false',
        maxChoices: '1'
      });
      trueFalseInteraction.ele('prompt', {}, question.question);
      const trueFalseResponseDeclaration = xmlRoot.ele('responseDeclaration', {
        identifier: `RESPONSE_${question.douid}`,
        cardinality: 'single',
        baseType: 'boolean',
      });
      const trueFalseResponse = trueFalseResponseDeclaration.ele('correctResponse');
      trueFalseResponse.ele('value', {}, question.answer.toString());

      let reg = /[\u4E00-\u9FFF]|[\u3400-\u4DBF]|[\uF900-\uFAFF]|\u3000|[\uFE30-\uFE4F]|[\u3000-\u303F]|\u3000|\u3003|\u3008-\u3011|\u3014|\u3015|\u301C-\u301E|[\uFF01-\uFF5E]/;
      if (question.question.match(reg)) {
        trueFalseInteraction.ele('simpleChoice', { identifier: '1' }, '正確');
        trueFalseInteraction.ele('simpleChoice', { identifier: '0' }, '錯誤');
      } else {
        trueFalseInteraction.ele('simpleChoice', { identifier: '1' }, 'True');
        trueFalseInteraction.ele('simpleChoice', { identifier: '0' }, 'False');
      }
      break;

    case 'QBShortAnswer':
    case 'QBLongQuestion':
      itemBody.ele('extendedTextInteraction', {
        responseIdentifier: `RESPONSE_${question.douid}`,
        expectedLength: "200"
      }).ele('prompt', {}, question.question);

      const shortAnswerResponseDeclaration = xmlRoot.ele('responseDeclaration', {
        identifier: `RESPONSE_${question.douid}`,
        cardinality: 'single',
        baseType: 'string'
      });
      const shortAnswerCorrectResponse = shortAnswerResponseDeclaration.ele('correctResponse');
      shortAnswerCorrectResponse.ele('value', {}, question.answer.toString());
      break;

    case 'QBFillingBlank':
      const fillingBlank = itemBody.ele('extendedTextInteraction', {
        responseIdentifier: `RESPONSE_${question.douid}`,
        expectedLength: "200"
      });

      let correctQ = question.question.replace(/\[.*?\]/g, "____");
      fillingBlank.ele('prompt', {}, correctQ);

      const fillingBlankResponseDeclaration = xmlRoot.ele('responseDeclaration', {
        identifier: `RESPONSE_${question.douid}`,
        cardinality: 'single',
        baseType: 'string'
      });
      const fillingBlankCorrectResponse = fillingBlankResponseDeclaration.ele('correctResponse');
      const correctAnswer = question.question.match(/\[(.*?)\]/)[1];
      fillingBlankCorrectResponse.ele('value', {}, correctAnswer);
      break;

    case 'QBInfoBlock':
      itemBody.ele('extendedTextInteraction', {
        responseIdentifier: `RESPONSE_${question.douid}`,
        expectedLength: "200"
      }).ele('prompt', {}, question.question);
      break;

//unusual case as below:
    case 'QBToggleOptions':
      itemBody.ele('hotspotInteraction', {
        responseIdentifier: `RESPONSE_${question.douid}`,
        maxChoices: "1"
      }).ele('prompt', {}, question.question);
      break;

    case 'QBRecorder':
    case 'QBTakePhoto':
      itemBody.ele('uploadInteraction', {
        responseIdentifier: `RESPONSE_${question.douid}`
      }).ele('prompt', {}, question.question);
      break;

    case 'QBDragLine':
    case 'DragDropA':
    case 'DragDropC':
      itemBody.ele('associateInteraction', {
        responseIdentifier: `RESPONSE_${question.douid}`,
        shuffle: "false",
        maxAssociations: "3"
      }).ele('prompt', {}, question.question);
      break;

    default:
      console.warn(`Unknown question type: ${question.type}`);
  }

  return xmlRoot.end({ pretty: true });
}

function createManifest(questionFiles, lang = 'en') {
  const manifest = xmlbuilder.create('manifest', { version: '1.0', encoding: 'UTF-8' })
    .att('xmlns', 'http://www.imsglobal.org/xsd/imscp_v1p1')
    .att('xmlns:imsmd', 'http://www.imsglobal.org/xsd/imsmd_v1p2')
    .att('xmlns:imsqti', 'http://www.imsglobal.org/xsd/imsqti_v2p1')
    .att('xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance')
    .att('identifier', `MANIFEST-QTI-${uuidv4()}`)
    .att('xsi:schemaLocation', 'http://www.imsglobal.org/xsd/imscp_v1p1 imscp_v1p1.xsd http://www.imsglobal.org/xsd/imsmd_v1p2 imsmd_v1p2p2.xsd http://www.imsglobal.org/xsd/imsqti_v2p1 imsqti_v2p1.xsd');

  manifest.ele('organizations');
  const resources = manifest.ele('resources');

  // const questionType = questions.question.type;
//媒體文件
  questionFiles.forEach((file, index) => {
    const resource = resources.ele('resource', {
      identifier: `qti_item_${index + 1}_${uuidv4()}`, 
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
    title.ele('imsmd:langstring', { 'xml:lang': lang }, `這是示例題目 #${index + 1}`);
    // title.ele('imsmd:langstring', { 'xml:lang': lang }, `這是示例題目`);

    const description = general.ele('imsmd:description');
    description.ele('imsmd:langstring', { 'xml:lang': lang }, '這是一個示例題目的説明');

    resource.ele('file', { href: file });
  });

  return manifest.end({ pretty: true });
}


const manifestContent = createManifest(['question1.xml', 'question2.xml'], 'zh');
// console.log(manifestContent);


app.post('/convert', async (req, res) => {
  try {
    const questionFiles = [];
    const subfolderName = `qti_content_${Date.now()}`;
    const subfolderPath = path.join(TEMP_DIR, subfolderName);

    await fs.ensureDir(subfolderPath);

    const promises = req.body.questions.map(async (question, index) => {
      const xmlOutput = jsonToQtiXml(question);
      const fileName = `assessment_item_${String(index + 1).padStart(3, '0')}.xml`;
      const filePath = path.join(subfolderPath, fileName);

      await fs.writeFile(filePath, xmlOutput);
      questionFiles.push(fileName);

      if (question.asset && question.asset.files) {
        const filePromises = question.asset.files.map(async file => {
          if (file.url) {
            const fullUrl = `https://oka.blob.core.windows.net/media/${file.url}`;
            const fileName = file.name.toLowerCase();
            const mediaFileName = path.join(subfolderPath,  fileName);
            await fs.ensureDir(path.dirname(mediaFileName));
            const response = await axios.get(fullUrl, { responseType: 'arraybuffer' });
            await fs.writeFile(mediaFileName, response.data);
            console.log(`Saved file: ${mediaFileName}`);
          }
        });
        await Promise.all(filePromises);
      }
    });

    await Promise.all(promises);

    const manifestOutput = createManifest(questionFiles);
    const manifestFileName = 'imsmanifest.xml';
    await fs.writeFile(path.join(subfolderPath, manifestFileName), manifestOutput);

    const zipFileName = `${subfolderName}.zip`;
    const zipFilePath = path.join(TEMP_DIR, zipFileName);
    const output = fs.createWriteStream(zipFilePath);
    const archive = archiver('zip');

    output.on('close', async () => {
      console.log(`ZIP file ${zipFileName} created successfully.`);
      await res.download(zipFilePath);
      await fs.remove(subfolderPath);
    });

    archive.on('error', err => {
      throw err;
    });

    archive.pipe(output);
    archive.directory(subfolderPath, false);
    await archive.finalize();
  } catch (error) {
    console.error('Error converting JSON to XML:', error);
    res.status(500).send('Error converting JSON to XML: ' + error.message);
  }
});

cron.schedule('0 0 * * *', async () => {
  try {
    const files = await fs.readdir(TEMP_DIR);
    const now = Date.now();
    const deletionPromises = files.map(async file => {
      const filePath = path.join(TEMP_DIR, file);
      const stats = await fs.stat(filePath);
      if ((now - stats.mtimeMs) > 7 * 24 * 60 * 60 * 1000) { // 文件超過7天會被自動刪除
        await fs.unlink(filePath);
        console.log(`Deleted old file: ${file}`);
      }
    });
    await Promise.all(deletionPromises);
  } catch (error) {
    console.error('Error during cron job execution:', error);
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

app.use((req, res, next) => {
  res.header("Access-Control-Allow-Origin", "*");
  res.header("Access-Control-Allow-Headers", "Origin, X-Requested-With, Content-Type, Accept");
  next();
});