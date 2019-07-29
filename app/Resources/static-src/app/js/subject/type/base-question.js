import AttachmentActions from '../../attachment/widget/attachment-actions';

class BaseQuestion {
  constructor($form, object) {
    this.$form = $form;
    this.operate = object;
    this.titleFieldId = 'question-stem-field';
    this.$analysisModal = $('.js-analysis-modal');
    this.validator = null;
    this.titleEditorToolBarName = 'Minimal';
    this._init();
    this.attachmentActions = new AttachmentActions($form);
    this.editor = null;
  }

  _init() {
    this._initEvent();
    this._initValidate();
  }

  _initEvent() {
    this.$form.on('click', '.subject-button', event => this.submitForm(event));
    this.$form.on('click', '.js-analysis-edit', event => this.showAnalysisModal(event));
    this.$analysisModal.on('click', '.js-analysis-btn', event => this.saveAnalysis(event));
  }

  submitForm(event) {
    let self = this;

    if (self.validator.form()) {
      $(event.currentTarget).button('loading');
      let question = self.getQuestion();
      self.finishEdit(question);
    }
  }

  showAnalysisModal(event) {
    let self = this;
    let $target = $(event.currentTarget);
    let analysis = $target.prev('[data-edit="analysis"]').val();
    let $textarea = $('.js-analysis-field');
    $textarea.val(analysis);
    self.editor = CKEDITOR.replace($textarea.attr('id'), {
      toolbar: self.titleEditorToolBarName,
      fileSingleSizeLimit: app.fileSingleSizeLimit,
      filebrowserImageUploadUrl: $textarea.data('imageUploadUrl'),
      height: $textarea.height()
    });

    self.editor.on('change', () => {
      $textarea.val(self.editor.getData());
    });
    self.editor.on('blur', () => {
      $textarea.val(self.editor.getData());
    });

    self.editor.on('instanceReady', function() {
      this.focus();

      self.$analysisModal.on('hide.bs.modal', function() {
        self.editor.destroy();
        $textarea.show();
      });
    });

    self.$analysisModal.modal('show');
  }

  saveAnalysis(event) {
    let data = this.editor.getData();
    $('[data-edit="analysis"]').val(data);
    $('.js-analysis-content').html($(this.replacePicture(data)).text());
    this.$analysisModal.modal('hide');
  }

  finishEdit(question) {
    let self = this;
    let token = $('.js-hidden-token').val();
    let method = $('.js-hidden-method').val();
    let isSub = $('.js-sub-judge').val();
    let key = $('.js-edit-form-seq').text() - 1;
    let seq = 0;
    if (isSub == '1') {
      token = self.updataCachedSubQuestion(token, key, question, method);
      question = self.operate.getSubQuestion(token, key);
      seq = key + 1;
    } else {
      token = self.updataCachedQuestion(token, question, method);
      question = self.operate.getQuestion(token);
      seq = self.operate.getQuestionOrder(token);
    }

    $.post(self.$form.data('url'), {'seq': seq, 'question': question, 'token': token, 'isSub': isSub}, html=> {
      self.$form.parent('.subject-item').replaceWith(html);
      if (isSub != '1') {
        self.removeErrorClass(token);
      }
    });
  }

  updataCachedQuestion(token, question, method) {
    let self = this;
    if (method == 'add') {
      token = self.operate.addQuestion(token, question);
    } else {
      $.each(question, function(name, value){
        self.operate.updateQuestionItem(token, name, value);
      });
    }

    return token;
  }

  updataCachedSubQuestion(token, key, question, method) {
    let self = this;
    if (method == 'add') {
      let preToken = $('.subject-edit-item').prev('.js-subject-item').attr('id');
      token = self.operate.addSubQuestion(preToken, question);
    } else {
      $.each(question, function(name, value){
        self.operate.updateSubQuestionItem(token, key, name, value);
      });
    }

    return token;
  }

  getQuestion() {
    let question = {};

    $('*[data-edit]').each(function(){
      let name = $(this).data('edit');
      let value = $(this).val();
      question[name] = value;
    });
    question['difficulty'] = $('input[name=\'difficulty\']:checked').val();
    question['attachments'] = this.getAttachemnts();
    question = this.filterQuestion(question);

    return question;
  }

  filterQuestion(question) {
    return question;
  }

  getAttachemnts() {
    let attachments = {};
    if ($('.js-attachment-list-stem').find('.js-attachment-name').length > 0) {
      attachments['stem'] = {
        "fileId" : $('.js-attachment-ids-stem').val(),
        "fileName" : $('.js-attachment-list-stem').find('.js-attachment-name').text()
      };
    }

    if ($('.js-attachment-list-analysis').find('.js-attachment-name').length > 0) {
      attachments['analysis'] = {
        "fileId" : $('.js-attachment-ids-analysis').val(),
        "fileName" : $('.js-attachment-list-analysis').find('.js-attachment-name').text()
      };
    }
    
    return attachments;
  }

  removeErrorClass(token) {
    if ($(`[data-anchor="#${token}"]`).hasClass('subject-list-item__num--error')) {
      $(`[data-anchor="#${token}"]`).removeClass('subject-list-item__num--error');
    }
  }

  _initValidate() {
    let validator = this.$form.validate({
      onkeyup: false,
      rules: {
        difficulty: {
          required: true,
        },
        stem: {
          required: true,
        },
        score: {
          required: true,
          number: true,
          max: 999,
          min: 0,
          es_score: true
        }
      },
      messages: {
        difficulty: Translator.trans('course.question.create.difficulty_required_error_hint'),
        stem: {required: Translator.trans('请输入题干')}
      }
    });
    this.validator = validator;
  }

  initTitleEditor(validator) {
    let self = this;
    let $target = $('#' + self.titleFieldId);
    let $input = $target.prev();
    let editor = CKEDITOR.replace(self.titleFieldId, {
      toolbar: self.titleEditorToolBarName,
      fileSingleSizeLimit: app.fileSingleSizeLimit,
      filebrowserImageUploadUrl: $target.data('imageUploadUrl'),
      height: $target.height()
    });

    editor.on('change', () => {
      $target.val(editor.getData());
      // validator.form();
      $input.val($(self.replacePicture(editor.getData())).text());
    });
    editor.on('blur', () => {
      $target.val(editor.getData());
      // validator.form();
      $input.val($(self.replacePicture(editor.getData())).text());
    });
    editor.on('instanceReady', function() {
      this.focus();

      $('[data-role="edit"]').one('click', function() {
        $input.val($(self.replacePicture(editor.getData())).text());
        $target.val(editor.getData());
        editor.destroy();
        $target.hide();
        $input.show();
      });
    });
  }

  replacePicture(str) {
    return str.replace(/<img [^>]*src=['"]([^'"]+)[^>]*>/gi, '[图片]');
  }

  set titleEditorToolBarName(toolbarName) {
    this._titleEditorToolBarName = toolbarName;
  }

  get titleEditorToolBarName() {
    return this._titleEditorToolBarName;
  }
}

export default BaseQuestion;