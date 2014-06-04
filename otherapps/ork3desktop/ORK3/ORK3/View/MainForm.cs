using System;
using System.Collections.Generic;
using System.ComponentModel;
using System.Data;
using System.Drawing;
using System.Linq;
using System.Text;
using System.Windows.Forms;

namespace ORK3.View
{
    public delegate void OpenSettingsFormHandler();

    public partial class MainForm : Form
    {
        public event OpenSettingsFormHandler OpenSettingsForm = delegate { };

        public MainForm()
        {
            InitializeComponent();
        }

        private void optionsToolStripMenuItem_Click(object sender, EventArgs e)
        {
            OpenSettingsForm();
        }
    }
}
