const express = require('express');
const bodyParser = require('body-parser');
const app = express();
app.use(bodyParser.json());

const jsonData = 
    {
        "result": {
            "Total_score": "N/A",
            "School": "N/A",
            "Period": "N/A",
            "Grade": "N/A",
            "Subject": "N/A",
            "Section": [
                {
                    "id": "一",
                    "QuestionType": "True or False",
                    "Instruction": "是非題",
                    "QuestionKey": [
                        {
                            "Passage": "No",
                            "Question": "今天天氣很好 是/非",
                            "Score": "N/A",
                            "Options": [
                                "是",
                                "非"
                            ],
                            "Answer": "是"
                        },
                    ]
                }
            ]
        },
    }

function jsonToQti(jsonData) {
    console.log(jsonData);
    const qti = {
        //set format of varity of accessment type to JSON
        //目前只做選擇 填充 是非
        "assessmentItem": {
            "xmlns": "http://www.imsglobal.org/xsd/imsqti_v2p2",
            "identifier":"sample",
            "title":"sample test",
            "adaptable": false,
            "ItemBody": {
                "":"",


            },
        "responseDeclaration": [
            {
                "responseType": "choice",
                "correctResponse": [jsonData.QuestionKey.Answer]
            },
            {
                "responseType": "Filling",
                "question":[jsonData.QuestionKey.Question],
                "correctResponse": [jsonData.QuestionKey.Answer]
            },

        ],
        
            "itemType": "choice",
            "shuffle": jsonData.shuffle || false,
            "choices": jsonData.choices.map(choice => ({
                "text": choice,
                "correct": choice === jsonData.QuestionKey.Answer,
            }))

        }
    }
    
}

// response-declaration 
// choice-interaction 選擇題 包括單選和多選
    //simple-choice 單選/是非
    //Multiple Choice 多選
//textEntryInteraction
    //Fill in the Blank 供詞填充
//extendedTextInteraction
    //長答